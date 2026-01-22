<?php

declare(strict_types=1);

namespace Radix\Http;

use Radix\Support\Validator;

abstract class FormRequest
{
    protected Validator $validator;
    /** @var array<string, mixed> */
    protected array $data;

    public function __construct(protected Request $request)
    {
        $this->data = $request->post;
    }

    /**
     * Definiera valideringsregler
     *
     * @return array<string, string|array<int, string>>
     */
    abstract public function rules(): array;

    /**
     * Kör valideringen
     */
    public function validate(): bool
    {
        /** @var array<string, string|array<int, string>> $rules */
        $rules = $this->rules();

        // Hook för att lägga till extra regler (t.ex. honeypot)
        $rules = $this->addExtraRules($rules);

        $this->validator = new Validator($this->data, $rules);
        $isValid = $this->validator->validate();

        // Hook för att hantera validerings-fel (t.ex. honeypot-fel)
        if (!$isValid) {
            $this->handleValidationErrors();
        }

        return $isValid;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->validator->errors();
    }

    /**
     * Returnerar endast de fält som definierats i reglerna (säkert)
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return array_intersect_key($this->data, $this->rules());
    }

    /**
     * Hook för att lägga till extra valideringsregler.
     * Kan overridas i subklasser för att lägga till honeypot etc.
     *
     * @param array<string, string|array<int, string>> $rules
     * @return array<string, string|array<int, string>>
     */
    protected function addExtraRules(array $rules): array
    {
        return $rules;
    }

    /**
     * Hook för att hantera validerings-fel efter validering.
     * Kan overridas för att konvertera honeypot-fel till form-error etc.
     */
    protected function handleValidationErrors(): void
    {
        // Ingen standard-hantering
    }
}
