<?php

namespace App\Http\Requests\TimeTable;

use Illuminate\Foundation\Http\FormRequest;

class UseTimeSlotRequest extends FormRequest
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
            'ttr_id' => 'required',
        ];
    }

    public function attributes()
    {
        return [
            'ttr_id' => 'TimeTable Record',
        ];
    }

}
