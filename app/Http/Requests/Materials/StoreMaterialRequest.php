<?php

namespace App\Http\Requests\Materials;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMaterialRequest extends FormRequest
{
    public const TYPE_MESSAGE = 'Upload a PDF, Word, Pages, text or Markdown file.';

    public const SIZE_MESSAGE = 'Keep files under 10 MB.';

    /**
     * Any signed in learner may upload; a file only ever belongs to who uploaded it.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The extension says what the file claims to be, the content type what it is: both must fit. A Pages file
     * is a zip, and Word for Mac sometimes saves .docx files that are detected as one too.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                'extensions:pdf,docx,doc,pages,txt,md',
                'mimetypes:application/pdf,'
                    .'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
                    .'application/msword,application/x-ole-storage,application/CDFV2,'
                    .'application/zip,'
                    .'text/plain,text/markdown,text/x-markdown',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Pick a file to upload.',
            // A file over PHP's own limit arrives as a failed upload rather than a large file.
            'file.file' => self::SIZE_MESSAGE,
            'file.uploaded' => self::SIZE_MESSAGE,
            'file.max' => self::SIZE_MESSAGE,
            'file.extensions' => self::TYPE_MESSAGE,
            'file.mimetypes' => self::TYPE_MESSAGE,
        ];
    }
}
