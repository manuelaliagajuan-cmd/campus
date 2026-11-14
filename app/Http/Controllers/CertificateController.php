<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Course;
use App\Services\Certificates;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CertificateController extends Controller
{
    public function index(Request $r)
    {
        return view('certificates.index', ['certificates' => Certificate::where('user_id', $r->user()->id)->latest('issued_at')->paginate(12)]);
    }

    public function issue(Request $r, Course $course)
    {
        abort_unless($course->accessibleBy($r->user()), 403);
        $certificate = Certificates::issueIfEligible($r->user(), $course);
        if (! $certificate) {
            return back()->with('status', __('Completa todas las lecciones y supera los tests evaluables para obtener tu certificado.'));
        }

return redirect()->route('certificates.show', $certificate);
    }

    private function authorizeCertificate(Request $r, Certificate $certificate): void
    {
        abort_unless($certificate->user_id === $r->user()->id || $certificate->course->managedBy($r->user()), 403);
    }

    public function show(Request $r, Certificate $certificate)
    {
        $this->authorizeCertificate($r, $certificate);

        return view('certificates.show', compact('certificate'));
    }

    public function pdf(Request $r, Certificate $certificate)
    {
        $this->authorizeCertificate($r, $certificate);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', storage_path('framework/cache'));
        $options->set('fontCache', storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('certificates.pdf', compact('certificate'))->render(), 'UTF-8');
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();
        $name = 'CEC-'.Str::slug($certificate->course_title).'.pdf';

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($r->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$name.'"', 'Cache-Control' => 'private, no-store']);
    }
}
