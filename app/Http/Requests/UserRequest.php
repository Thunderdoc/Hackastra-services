<?php

namespace App\Http\Requests;

use App\Helpers\Qs;
use Illuminate\Foundation\Http\FormRequest;

class UserRequest extends FormRequest
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
        $store =  [
            'name' => 'required|string|min:6|max:150',
            'password' => 'nullable|string|min:3|max:50',
            'user_type' => 'required',
            'gender' => 'required|string',
            'phone' => 'sometimes|nullable|string|min:6|max:20',
            'email' => 'sometimes|nullable|email|max:100|unique:users',
            'username' => 'sometimes|nullable|alpha_dash|min:8|max:100|unique:users',
            'photo' => 'sometimes|nullable|image|mimes:jpeg,gif,png,jpg,webp|max:2048',
            'address' => 'required|string|min:6|max:120',
            'state_id' => 'required',
            'lga_id' => 'nullable',
            'nal_id' => 'required',
        ];
        $update =  [
            'name' => 'required|string|min:6|max:150',
            'gender' => 'required|string',
            'phone' => 'sometimes|nullable|string|min:6|max:20',
            'phone2' => 'sometimes|nullable|string|min:6|max:20',
            'email' => 'sometimes|nullable|email|max:100|unique:users,email,'.$this->user,
            'photo' => 'sometimes|nullable|image|mimes:jpeg,gif,png,jpg,webp|max:2048',
            'address' => 'required|string|min:6|max:120',
            'state_id' => 'required',
            'lga_id' => 'nullable',
            'nal_id' => 'required',
            /* Per-teacher caps (2026-11-06): blank = the school
               default. Sane ranges only — a day holds at most 12
               periods anywhere in this app, and a week cap below
               the day cap could never be met (pair-checked in
               withValidator + the controller). */
            'max_periods_per_day' => 'sometimes|nullable|integer|min:1|max:12',
            'max_periods_per_week' => 'sometimes|nullable|integer|min:1|max:60',
        ];
        return ($this->method() === 'POST') ? $store : $update;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $day = $this->input('max_periods_per_day');
            $week = $this->input('max_periods_per_week');
            if ($day !== null && $day !== '' && $week !== null && $week !== ''
                && (int) $week < (int) $day) {
                $v->errors()->add('max_periods_per_week',
                    'The weekly cap cannot be lower than the daily cap (' . (int) $day . ').');
            }
        });
    }

    public function attributes()
    {
        return  [
            'nal_id' => 'Nationality',
            'state_id' => 'State',
            'lga_id' => 'LGA',
            'user_type' => 'User',
            'phone2' => 'Telephone',
        ];
    }

    protected function getValidatorInstance()
    {
        if($this->method() === 'POST'){
            $input = $this->all();

            $input['user_type'] = ($input['user_type'] ?? null) ? Qs::decodeHash($input['user_type']) : null;

            $this->getInputSource()->replace($input);

        }

        if($this->method() === 'PUT'){
            $this->user = Qs::decodeHash($this->user);
        }

        return parent::getValidatorInstance();

    }
}
