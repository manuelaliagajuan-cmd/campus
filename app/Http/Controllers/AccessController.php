<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AccessController extends Controller
{
    private function feedbackDirectory(): ?string
    {
        $projection = config('erp.projection_path');

        return $projection ? dirname($projection).'/pass-feedback' : null;
    }

    private function completedPasses(Request $request): array
    {
        $erp = $request->attributes->get('erp', []);
        if ($request->user()->role !== 'student' || ($erp['state'] ?? '') !== 'connected') {
            return [];
        }

        return collect($erp['passes'] ?? [])->filter(fn ($pass) => $pass['sessions'] > 0
            && $pass['used'] >= $pass['sessions'])->keyBy('id')->all();
    }

    private function responseKey(Request $request, string $pass): string
    {
        return hash('sha256', json_encode([$request->user()->erp_student_id, $pass], JSON_THROW_ON_ERROR));
    }

    public function index(Request $request)
    {
        $courses = Course::accessibleTo($request->user())->with('teacher')->orderBy('title')->get();
        $erp = $request->attributes->get('erp', ['state' => 'unavailable', 'passes' => [], 'updated_at' => null]);
        $directory = $this->feedbackDirectory();
        $feedback = $directory ? array_map(fn ($pass) => $pass + [
            'submitted' => is_file($directory.'/'.$this->responseKey($request, $pass['id']).'.json'),
        ], $this->completedPasses($request)) : [];

        return view('accesses', compact('courses', 'erp', 'feedback'));
    }

    public function feedback(Request $request)
    {
        abort_unless($request->user()->role === 'student', 403);
        $answers = $request->validate([
            'pass' => 'required|string|max:100',
            'overall' => 'required|integer|between:1,5',
            'teaching' => 'required|integer|between:1,5',
            'progress' => 'required|integer|between:1,5',
            'schedule' => 'required|integer|between:1,5',
            'value' => 'required|integer|between:1,5',
            'comment' => 'nullable|string|max:1500',
        ]);
        $pass = $this->completedPasses($request)[$answers['pass']] ?? null;
        abort_unless($pass, 404);
        $directory = $this->feedbackDirectory();
        $file = $directory.'/'.$this->responseKey($request, $pass['id']).'.json';
        $lock = null;
        $temp = null;

        try {
            if (!$directory || (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory))) {
                throw new \RuntimeException('Feedback directory unavailable');
            }
            $lock = fopen($directory.'/.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Feedback lock unavailable');
            }
            if (!is_file($file)) {
                $ratings = array_map('intval', array_intersect_key($answers, array_flip(['overall','teaching','progress','schedule','value'])));
                $json = json_encode([
                    'submitted_at' => now()->toIso8601String(),
                    'pass_name' => $pass['name'],
                    'ratings' => $ratings,
                    'comment' => $answers['comment'] ?? '',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $temp = tempnam($directory, 'response-');
                if (!$temp || !chmod($temp, 0600) || file_put_contents($temp, $json) !== strlen($json) || !rename($temp, $file)) {
                    throw new \RuntimeException('Feedback could not be saved');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar una encuesta de bono', ['type' => get_class($e)]);

            return back()->withInput()->withErrors(['feedback' => 'No se pudo enviar la encuesta. Inténtalo de nuevo.']);
        } finally {
            if (is_string($temp) && is_file($temp)) {
                unlink($temp);
            }
            if (is_resource($lock)) {
                fclose($lock);
            }
        }

        return redirect()->route('accesses')->with('status', 'Gracias. Tu encuesta ya está disponible para administración.');
    }
}
