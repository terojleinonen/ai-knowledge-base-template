<?php

namespace App\Services\Retrieval;

use Laravel\Ai\Embeddings;

class Embedder
{
    public function __construct(
        private readonly string $provider,
        private readonly ?string $model,
        private readonly ?int $dimensions = null,
        private readonly int $batchSize = 64,
        private readonly int $timeout = 60,
    ) {}

    /**
     * Embed the given texts, returning unit-length vectors in input order.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk($texts, max(1, $this->batchSize)) as $batch) {
            $request = Embeddings::for($batch)->timeout($this->timeout);

            if ($this->dimensions !== null) {
                $request->dimensions($this->dimensions);
            }

            foreach ($request->generate($this->provider, $this->model)->embeddings as $vector) {
                $vectors[] = VectorCodec::normalize($vector);
            }
        }

        return $vectors;
    }

    /**
     * Identifier stored alongside vectors so incompatible embeddings are never compared.
     */
    public function identifier(): string
    {
        return implode(':', array_filter([$this->provider, $this->model, $this->dimensions]));
    }
}
