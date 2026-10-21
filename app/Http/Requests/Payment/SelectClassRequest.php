<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class SelectClassRequest extends FormRequest
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
            'my_class_id' => 'required|exists:my_classes,id',
        ];
    }

    public function attributes()
    {
        return [
            'my_class_id' => 'Class',
        ];
    }

}
