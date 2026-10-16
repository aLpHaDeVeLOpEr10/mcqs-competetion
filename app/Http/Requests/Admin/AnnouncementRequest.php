<?php

namespace App\Http\Requests\Admin;

use App\Enums\AnnouncementAudience;
use App\Enums\RoomStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('announcements.manage');
    }

    /**
     * Plain text only: it is always escaped when shown.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'audience' => ['required', Rule::enum(AnnouncementAudience::class)],
            'room_id' => ['nullable', 'required_if:audience,room', 'integer',
                Rule::exists('rooms', 'id')->whereNot('status', RoomStatus::Draft->value)],
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required_if' => 'Choose the competition whose players should receive this.',
            'room_id.exists' => 'Choose a published competition.',
            'confirm.accepted' => 'Tick the box to confirm sending.',
        ];
    }
}
