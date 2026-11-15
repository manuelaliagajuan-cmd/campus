<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class LessonCancelled extends Mailable
{
    public function __construct(public array $details) {}

    public function build(): static
    {
        return $this->subject('CEC · Cancelación de clase')->text('emails.lesson-cancelled', ['details' => $this->details]);
    }
}
