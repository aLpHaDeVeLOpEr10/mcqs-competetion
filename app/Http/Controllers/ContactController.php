<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Mail\ContactMessageReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

class ContactController extends Controller
{
    public function show(): View
    {
        return view('pages.contact', [
            'available' => $this->recipient() !== null,
            'topics' => ContactRequest::TOPICS,
        ]);
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $recipient = $this->recipient();

        if ($recipient === null) {
            return back()->withInput()->with('error', 'The contact form is not available right now.');
        }

        $data = $request->validated();

        try {
            Mail::to($recipient)->send(new ContactMessageReceived(
                senderName: $data['name'],
                senderEmail: $data['email'],
                topicLabel: ContactRequest::TOPICS[$data['topic']],
                body: $data['message'],
            ));
        } catch (Throwable $e) {
            report($e);
            Log::warning('Contact message could not be sent.', ['topic' => $data['topic']]);

            return back()->withInput()->with('error', 'We could not send your message. Please try again later.');
        }

        return redirect()->route('contact')->with('success', 'Thanks — your message has been sent. We will reply by email.');
    }

    protected function recipient(): ?string
    {
        return config('competition.contact.recipient') ?: null;
    }
}
