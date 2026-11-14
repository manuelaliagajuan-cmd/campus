<?php

namespace App\Http\Controllers;

use App\Models\PassContent;
use App\Services\ErpConnection;
use App\Services\YouTube;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PassWorkspaceController extends Controller
{
    private function workspace(Request $r, string $id): array
    {
        $pass = collect(ErpConnection::workspaces($r->user()))->firstWhere('id', $id);
        abort_unless($pass, 403);

        return $pass;
    }

    public function index(Request $r)
    {
        abort_unless($r->user()->isStaff(), 403);
        $passes = ErpConnection::workspaces($r->user());

        return view('passes.index', compact('passes'));
    }

    public function show(Request $r, string $pass)
    {
        $workspace = $this->workspace($r, $pass);
        $contents = PassContent::where('erp_pass_id', $pass)->where('erp_student_id', $workspace['student_id'])->when(! $workspace['manage'], fn ($q) => $q->where('published', true))->with('author')->latest()->get();

        return view('passes.show', compact('workspace', 'contents'));
    }

    public function store(Request $r, string $pass)
    {
        $workspace = $this->workspace($r, $pass);
        abort_unless($workspace['manage'], 403);
        $d = $r->validate(['title' => 'required|string|max:180', 'body' => 'nullable|required_without_all:file,youtube_url|string|max:20000', 'youtube_url' => 'nullable|url|max:500', 'file' => 'nullable|file|max:20480|mimes:pdf,jpg,jpeg,png,webp,mp3,mp4,docx,xlsx,pptx', 'published' => 'nullable|boolean']);
        $youtube = YouTube::id($d['youtube_url'] ?? null);
        if (! empty($d['youtube_url']) && ! $youtube) {
            throw ValidationException::withMessages(['youtube_url' => __('Introduce un enlace de YouTube válido.')]);
        }
        $row = ['erp_pass_id' => $pass, 'erp_student_id' => $workspace['student_id'], 'author_id' => $r->user()->id, 'title' => $d['title'], 'body' => $d['body'] ?? null, 'youtube_id' => $youtube, 'published' => $r->boolean('published')];
        $path = null;
        if ($f = $r->file('file')) {
            $path = $f->store('pass-materials', 'local');
            $row += ['path' => $path, 'filename' => basename($f->getClientOriginalName()), 'mime' => $f->getMimeType(), 'size' => $f->getSize()];
        }
        try {
            PassContent::create($row);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }

        return back()->with('status', __('Contenido del bono guardado.'));
    }

    public function publish(Request $r, PassContent $content)
    {
        $workspace = $this->workspace($r, $content->erp_pass_id);
        abort_unless($content->erp_student_id === $workspace['student_id'], 403);
        abort_unless($workspace['manage'], 403);
        $r->validate(['published' => 'required|boolean']);
        $content->update(['published' => $r->boolean('published')]);

        return back()->with('status', __('Publicación actualizada.'));
    }

    public function destroy(Request $r, PassContent $content)
    {
        $workspace = $this->workspace($r, $content->erp_pass_id);
        abort_unless($content->erp_student_id === $workspace['student_id'], 403);
        abort_unless($workspace['manage'], 403);
        $path = $content->path;
        $content->delete();
        if ($path) {
            Storage::disk('local')->delete($path);
        }

        return back()->with('status', __('Contenido eliminado.'));
    }

    public function file(Request $r, PassContent $content)
    {
        $workspace = $this->workspace($r, $content->erp_pass_id);
        abort_unless($content->erp_student_id === $workspace['student_id'], 403);
        abort_unless($workspace['manage'] || $content->published, 403);
        abort_unless($content->path && Storage::disk('local')->exists($content->path), 404);

        return Storage::disk('local')->download($content->path, $content->filename, ['Content-Type' => $content->mime, 'X-Content-Type-Options' => 'nosniff']);
    }
}
