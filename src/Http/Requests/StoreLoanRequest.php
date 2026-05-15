<?php

namespace Blax\Shop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generic store-loan validation. Accepts a `loanable_id` referencing a row
 * in the products table. Host apps that want a different request key
 * (e.g. `book_id`) can subclass and override rules() / prepareForValidation().
 */
class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'loanable_id' => [
                'required',
                'uuid',
                'exists:'.config('shop.tables.products', 'products').',id',
            ],
        ];
    }

    /**
     * The validated id of the model to be checked out. Subclasses that
     * rename the field can override this to keep HandlesLoans agnostic.
     */
    public function loanableId(): string
    {
        return $this->validated($this->validationKey());
    }

    /**
     * The body / validation key used in `rules()`. HandlesLoans uses this
     * to attach the "no copies available" error to the correct field, so
     * subclasses that rename `loanable_id` should override here too.
     */
    public function validationKey(): string
    {
        return 'loanable_id';
    }
}
