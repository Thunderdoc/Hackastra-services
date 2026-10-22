<?php

namespace App\Http\Requests\Dorm;

use Illuminate\Foundation\Http\FormRequest;

class DormUpdate extends FormRequest
{

    public function authorize()
    {
        return true;
    }


    public function rules()
    {
        return [
            // Was 'unique:dorms,id,name' — column and except-value were
            // swapped, so uniqueness was never actually checked on
            // update. Correct form: unique name, ignoring this dorm.
            'name' => 'required|string|unique:dorms,name,' . $this->route('dorm'),
        ];
    }

}
