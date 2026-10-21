<?php

namespace App\Http\Requests\Book;

use App\Models\Book;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Library catalogue create + update share one rule set (no unique
 * fields), so a single request class serves both, mirroring how
 * BookController previously shared one validateBook() helper.
 */
class BookSave extends FormRequest
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
            'name' => 'required|string|max:100',
            'author' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'book_type' => 'nullable|in:' . implode(',', array_keys(Book::types())),
            'my_class_id' => 'nullable|exists:my_classes,id',
            'url' => 'nullable|url|max:255',
            'location' => 'nullable|string|max:255',
            'total_copies' => 'nullable|integer|min:0',
        ];
    }

}
