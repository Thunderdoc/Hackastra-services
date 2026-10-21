<?php

namespace App\Http\Requests\Attendance;

use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceCreate extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'date' => 'required|date',
            'class_id' => 'required|exists:my_classes,id',
            'statuses' => 'required|array',
            'statuses.*' => 'required|in:' . implode(',', Attendance::statuses()),
        ];
    }

}
