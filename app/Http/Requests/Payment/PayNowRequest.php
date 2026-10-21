<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class PayNowRequest extends FormRequest
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
            'amt_paid' => 'required|numeric',
        ];
    }

    public function attributes()
    {
        return [
            'amt_paid' => 'Amount Paid',
        ];
    }

}
