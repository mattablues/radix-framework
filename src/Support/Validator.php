<?php

declare(strict_types=1);

namespace Radix\Support;

use InvalidArgumentException;

class Validator
{
    protected array $data;
    protected array $rules;
    protected array $errors = [];

    protected array $fieldTranslations = [
        'name' => 'namn',
        'first_name' => 'förnamn',
        'last_name' => 'efternamn',
        'email' => 'e-post',
        'message' => 'meddelande',
        'password' => 'lösenord',
        'password_confirmation' => 'repetera lösenord',
        'honeypot' => 'honeypot',
    ];

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    /**
     * Kör valideringen och returnerar om allt är giltigt.
     */
    public function validate(): bool
    {
        // Hantera om filen har ett uppladdningsfel
        if (isset($this->data['error']) && $this->data['error'] !== UPLOAD_ERR_OK) {
            $this->errors['file'] = ['Filen laddades inte upp korrekt.'];
            return false;
        }

        foreach ($this->rules as $field => $rules) {
            $value = $this->data[$field] ?? null;
            $rules = is_string($rules) ? explode('|', $rules) : $rules;

            foreach ($rules as $rule) {
                $this->applyRule($field, $rule, $value);
            }
        }

        return empty($this->errors);
    }

    /**
     * Hämta alla valideringsfel.
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function addError(string $field, string $message, bool $includeValue = false): void
    {
        $value = $this->data[$field] ?? null;

        if ($includeValue && $value) {
            $message = str_replace('{placeholder}', htmlspecialchars($value, ENT_QUOTES), $message);
        }

        $this->errors[$field][] = $message;
    }

    protected function applyRule(string $field, string $rule, mixed $value): void
    {
        if (str_contains($rule, ':')) {
            [$rule, $parameter] = explode(':', $rule, 2);
        } else {
            $parameter = null;
        }

        // Om fältet är nullable och värdet är null
        if ($rule === 'nullable' && (is_null($value) || (is_array($value) && $value['error'] === UPLOAD_ERR_NO_FILE))) {
            return; // Ignorera helt
        }

        // Om regeln är 'sometimes', kontrollera om fältet finns innan validering
        if ($rule === 'sometimes') {
            if (!array_key_exists($field, $this->data)) {
                return; // Ignorera om fältet inte finns
            }
        }

        $method = 'validate' . str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($rule))));

        if (!method_exists($this, $method)) {
            throw new InvalidArgumentException("Valideringsregeln '$rule' stöds inte.");
        }

        // För regeln 'confirmed'
        if ($rule === 'confirmed') {
            $parameter = rtrim($field, '_confirmation'); // Ta bort "_confirmation" för att få huvudfältet
        }

        if (in_array($rule, ['file_type', 'file_size']) && is_array($this->data) && isset($this->data[$field])) {
            $value = $this->data[$field];
        }

        if (!$this->$method($value, $parameter)) {
            $this->errors[$field][] = $this->getErrorMessage($field, $rule, $parameter);
        }
    }

    protected function getErrorMessage(string $field, string $rule, mixed $parameter = null): string
    {
        // Översätt huvudfältet
        $translatedField = $this->fieldTranslations[str_replace('hp_', 'honeypot', $field)] ?? $field;

        if ($rule === 'confirmed') {
            // Korrekt huvudfält för "_confirmation"-fält
            $parameterField = $parameter ?? rtrim($field, '_confirmation');
            $translatedParameter = $this->fieldTranslations[$parameterField] ?? $parameterField;


            return "Fältet $translatedField måste matcha fältet $translatedParameter.";
        }

                // Hantering för "file_type" med tydligare meddelande
//        if ($rule === 'file_type') {
//            return "Fältet $translatedField måste vara av typen: $parameter.";
//        }


        // Översätt parameterfältet, t.ex. password i regeln 'confirmed'
        $translatedParameter = $parameter ? ($this->fieldTranslations[$parameter] ?? $parameter) : $parameter;

        // Standardfelmeddelanden
        $messages = [
            'required' => "Fältet $translatedField är obligatoriskt.",
            'email' => "Fältet $translatedField måste vara en giltig e-postadress.",
            'min' => "Fältet $translatedField måste vara minst $parameter tecken långt.",
            'max' => "Fältet $translatedField får inte vara längre än $parameter tecken.",
            'numeric' => "Fältet $translatedField måste vara numeriskt.",
            'alphanumeric' => "Fältet $translatedField får endast innehålla bokstäver och siffror.",
            'match' => "Fältet $translatedField måste matcha fältet $translatedParameter.",
            'honeypot' => "Spam.",
            'unique' => "Fältet $translatedField måste vara unikt, '{placeholder}' används redan.",
            'regex' => "Fältet $translatedField har ett ogiltigt format.",
            'in' => "Fältet $translatedField måste vara ett av följande värden: $parameter.",
            'not_in' => "Fältet $translatedField får inte vara ett av följande värden: $parameter.",
            'boolean' => "Fältet $translatedField måste vara sant eller falskt.",
            'confirmed' => "Fältet $translatedField måste matcha fältet $translatedParameter.",
            'date' => "Fältet $translatedField måste vara ett giltigt datum.",
            'date_format' => "Fältet $translatedField måste vara i formatet '$parameter'.",
            'starts_with' => "Fältet $translatedField måste börja med ett av följande: $parameter.",
            'ends_with' => "Fältet $translatedField måste sluta med ett av följande: $parameter.",
            'ip' => "Fältet $translatedField måste vara en giltig IP-adress.",
            'url' => "Fältet $translatedField måste vara en giltig URL.",
            'required_with' => "Fältet $translatedField är obligatoriskt när $translatedParameter anges.",
            'nullable' => "Fältet $translatedField får lämnas tomt, men om det anges måste det uppfylla valideringsreglerna.",
            'string' => "Fältet $translatedField måste vara en giltig textsträng.", // Lägg till detta
            'file_type' => "Fältet $translatedField måste vara av typen: $parameter.",
            'file_size' => "Fältet $translatedField får inte överstiga $parameter MB.",
        ];

        $message = $messages[$rule] ?? "Fältet $translatedField uppfyller inte valideringsregeln '$rule'.";

        // Om värdet finns, ersätt {placeholder} i felmeddelandet
        $value = $this->data[$field] ?? null;
        if (str_contains($message, '{placeholder}') && $value !== null) {
            $message = str_replace('{placeholder}', htmlspecialchars((string)$value, ENT_QUOTES), $message);
        }

        return $message;
    }

    protected function validateSometimes(mixed $value, ?string $parameter = null): bool
    {
        // Dummy-metod. Ingen inspektion krävs för 'sometimes', då det hanteras i applyRule.
        return true;
    }

    // Valideringsregler
    protected function validateRequired(mixed $value, ?string $parameter = null): bool
    {
        return !is_null($value) && $value !== '';
    }

    protected function validateString(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        // Kontrollera om värdet är null eller en sträng
        return is_string($value);
    }

    protected function validateRequiredWith(mixed $value, ?string $parameter = null): bool
    {
        if ($parameter === null) {
            throw new InvalidArgumentException("Valideringsregeln 'required_with' kräver en lista med fält.");
        }

        // Dela upp parametern som är en kommaseparerad lista på fält (t.ex. "field1,field2")
        $requiredFields = explode(',', $parameter);

        // Kontrollera om något av de angivna fälten har ett värde
        $areRequiredFieldsFilled = false;
        foreach ($requiredFields as $field) {
            if (!empty($this->data[$field])) {
                $areRequiredFieldsFilled = true;
                break;
            }
        }

        // Om något av de angivna fälten har ett värde, kontrollera att det aktuella fältet också har ett värde
        if ($areRequiredFieldsFilled) {
            return !is_null($value) && $value !== '';
        }

        // Om inget av de beroende fälten är ifyllt, returnera sant
        return true;
    }

    protected function validateNullable(mixed $value, ?string $parameter = null): bool
    {
        // Om värdet är null eller tomt ska det inte orsaka valideringsfel
        return true;
        // Om värdet inte är tomt, returnera true eftersom det inte påverkas av nullable
    }

    protected function validateEmail(mixed $value, ?string $parameter = null): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function validateMin(mixed $value, ?string $parameter = null): bool
    {
        // Om värdet är null (nullable) eller en tom sträng, returnera alltid true
        if (is_null($value) || $value === '') {
            return true;
        }

        // Kontrollera längden för värdet om det inte är tomt
        return is_string($value) && strlen($value) >= (int) $parameter;
    }

    protected function validateUrl(mixed $value, ?string $parameter = null): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    protected function validateMax(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return is_string($value) && strlen($value) <= (int) $parameter;
    }

    protected function validateNumeric(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return is_numeric($value);
    }

    protected function validateAlphanumeric(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return ctype_alnum($value); // Kontrollera om värdet endast består av alfanumeriska tecken
    }

    protected function validateMatch(mixed $value, ?string $parameter = null): bool
    {
        if ($parameter === null || !array_key_exists($parameter, $this->data)) {
            throw new InvalidArgumentException("Valideringsregeln 'match' kräver ett giltigt fält att jämföra med.");
        }

        return $value === $this->data[$parameter];
    }

    protected function validateRegex(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if ($parameter === null) {
            throw new InvalidArgumentException("Valideringsregeln 'regex' kräver ett regex-mönster.");
        }

        return preg_match($parameter, (string) $value) === 1;
    }

    protected function validateIn(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if ($parameter === null) {
            throw new InvalidArgumentException("Valideringsregeln 'in' kräver en lista med tillåtna värden.");
        }

        $allowedValues = explode(',', $parameter);
        return in_array((string)$value, $allowedValues, true);
    }

    protected function validateHoneypot(mixed $value, ?string $parameter = null): bool
    {
        return empty($value); // Ett giltigt honeypot-fält bör vara tomt
    }

    protected function validateNotIn(mixed $value, ?string $parameter = null): bool
    {
        if ($parameter === null) {
            throw new InvalidArgumentException("Valideringsregeln 'not_in' kräver en lista med otillåtna värden.");
        }

        $disallowedValues = explode(',', $parameter);
        return !in_array((string)$value, $disallowedValues, true);
    }

    protected function validateBoolean(mixed $value, ?string $parameter = null): bool
    {
        return is_bool($value) || in_array((string)$value, ['true', 'false', '1', '0'], true);
    }
    
    protected function validateDate(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return strtotime((string)$value) !== false;
    }

    protected function validateUnique(mixed $value, ?string $parameter = null): bool
    {
        $parts = explode(',', $parameter);
        $modelClass = $parts[0] ?? null;
        $column = $parts[1] ?? null;
        $excludeId = isset($parts[2]) ? intval(explode('=', $parts[2])[1]) : null;

        if (!$modelClass || !class_exists($modelClass)) {
            throw new InvalidArgumentException(
                "Valideringsregeln 'unique' kräver en giltig modellklass. Kontrollera att '$modelClass' existerar."
            );
        }

        if (!$column) {
            throw new InvalidArgumentException("Valideringsregeln 'unique' kräver att kolumn specificeras.");
        }

        // Bygg upp frågan och inkludera soft-deleted poster
        $query = $modelClass::query()->withSoftDeletes()->where($column, '=', $value);

        if ($excludeId) {
            $query->where($modelClass::getPrimaryKey(), '!=', $excludeId);
        }

        return !$query->first();
    }

    protected function validateDateFormat(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if ($parameter === null) {
            throw new InvalidArgumentException("Valideringsregeln 'date_format' kräver ett datumformat.");
        }

        $date = \DateTime::createFromFormat($parameter, (string)$value);

        return $date && $date->format($parameter) === $value;
    }

    protected function validateStartsWith(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if ($parameter === null) {
            throw new InvalidArgumentException("Valideringsregeln 'starts_with' kräver en lista med prefix.");
        }

        $prefixes = explode(',', $parameter);

        foreach ($prefixes as $prefix) {
            if (str_starts_with((string)$value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function validateEndsWith(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if ($parameter === null) {
            throw new InvalidArgumentException("Valideringsregeln 'ends_with' kräver en lista med suffix.");
        }

        $suffixes = explode(',', $parameter);

        foreach ($suffixes as $suffix) {
            if (str_ends_with((string)$value, $suffix)) {
                return true;
            }
        }

        return false;
    }

    protected function validateConfirmed(mixed $value, ?string $parameter = null): bool
    {
        // Matcha huvudfältet (t.ex. 'password') med '_confirmation'
        $confirmationField = "{$parameter}_confirmation";

        if (!array_key_exists($parameter, $this->data)) {
            // Kontrollera om huvudfältet finns
            $this->addError(
                $parameter,
                "Huvudfältet '$parameter' är obligatoriskt för att använda 'confirmed' regeln."
            );
            return false;
        }

        if (!array_key_exists($confirmationField, $this->data)) {
            // Kontrollera om '_confirmation'-fältet finns
            $this->addError(
                $confirmationField,
                "Bekräftelsefältet '$confirmationField' saknas."
            );
            return false;
        }

        // Jämför huvudvärdet med '_confirmation'-fältet
        return $this->data[$parameter] === $this->data[$confirmationField];
    }

    protected function validateIp(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    protected function validateFileType(mixed $value, ?string $parameter = null): bool
    {
        // Ignorera validering om fältet är "nullable" och ingen fil skickades
        if (isset($value['error']) && $value['error'] === UPLOAD_ERR_NO_FILE) {
            return true; // Anses validerad om ingen fil skickades
        }

        // Kontrollera om arrayen är korrekt
        if (!is_array($value) || empty($value['type'])) {
            return false;
        }

        // Kontrollera att parametern finns
        if (!$parameter) {
            throw new InvalidArgumentException("Parameter krävs för 'file_type'-regeln.");
        }

        // Tillåtna MIME-typer
        $allowedTypes = array_map('trim', explode(',', strtolower($parameter)));

        // Kontrollera att filens MIME-typ är tillåten
        return in_array(strtolower($value['type']), $allowedTypes, true);
    }

    protected function validateFileSize(mixed $value, ?string $parameter = null): bool
    {
        // Ignorera validering om fältet är "nullable" och ingen fil skickades
        if (isset($value['error']) && $value['error'] === UPLOAD_ERR_NO_FILE) {
            return true; // Anses validerad om ingen fil skickades
        }

        // Kontrollera om arrayen är korrekt
        if (!is_array($value) || empty($value['size'])) {
            return false;
        }

        // Kontrollera att parametern är en giltig siffra
        if (!$parameter || !is_numeric($parameter)) {
            throw new InvalidArgumentException("Parameter för 'file_size' måste vara en giltig siffra.");
        }

        // Max tillåtna filstorlek i bytes
        $maxBytes = (int)$parameter * 1024 * 1024;

        // Kontrollera om filens storlek ligger inom det tillåtna intervallet
        return $value['size'] <= $maxBytes;
    }

    // Konvertera bytes till MB
    protected function convertSizeToMB(int $sizeInBytes): float
    {
        return round($sizeInBytes / (1024 * 1024), 2);
    }
}