<?php

namespace App\Evaluation;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * An evaluation dataset: a directory with documents and a cases.json file.
 *
 * {
 *   "name": "Northwind sample",
 *   "documents": ["employee-handbook.md", "security-policy.docx"],
 *   "cases": [
 *     {"question": "How many vacation days?", "expect_documents": ["employee-handbook"], "facts": ["30", ["10", "ten"]]},
 *     {"question": "What is the capital of France?", "unanswerable": true}
 *   ]
 * }
 */
final readonly class Dataset
{
    /**
     * @param  list<string>  $documents  absolute file paths
     * @param  list<EvalCase>  $cases
     */
    public function __construct(
        public string $name,
        public array $documents,
        public array $cases,
    ) {}

    public static function load(string $path): self
    {
        $file = is_dir($path) ? rtrim($path, '/').'/cases.json' : $path;

        if (! is_file($file)) {
            throw new InvalidArgumentException("Dataset file not found: {$file}");
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            throw new InvalidArgumentException("Invalid JSON in {$file}: ".json_last_error_msg());
        }

        $validator = Validator::make($data, [
            'name' => ['sometimes', 'string'],
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['string'],
            'cases' => ['required', 'array', 'min:1'],
            'cases.*.question' => ['required', 'string'],
            'cases.*.expect_documents' => ['array', 'required_unless:cases.*.unanswerable,true'],
            'cases.*.expect_documents.*' => ['string'],
            'cases.*.facts' => ['sometimes', 'array'],
            'cases.*.unanswerable' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException("Invalid dataset {$file}:\n- ".implode("\n- ", $validator->errors()->all()));
        }

        $directory = dirname($file);
        $documents = [];
        $titles = [];

        foreach ($data['documents'] as $document) {
            $documentPath = str_starts_with($document, '/') ? $document : "{$directory}/{$document}";

            if (! is_file($documentPath)) {
                throw new InvalidArgumentException("Document not found: {$documentPath}");
            }

            $documents[] = $documentPath;
            $titles[] = pathinfo($documentPath, PATHINFO_FILENAME);
        }

        $cases = [];

        foreach ($data['cases'] as $i => $case) {
            $unknown = array_diff($case['expect_documents'] ?? [], $titles);

            if ($unknown !== []) {
                throw new InvalidArgumentException('Case #'.($i + 1).' expects unknown document(s): '.implode(', ', $unknown));
            }

            $cases[] = new EvalCase(
                question: $case['question'],
                expectDocuments: array_values($case['expect_documents'] ?? []),
                facts: array_map(fn ($fact) => array_values((array) $fact), $case['facts'] ?? []),
                unanswerable: (bool) ($case['unanswerable'] ?? false),
            );
        }

        return new self($data['name'] ?? basename($directory), $documents, $cases);
    }
}
