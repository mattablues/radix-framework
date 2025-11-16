<?php

declare(strict_types=1);

namespace Radix\Support;

use InvalidArgumentException;

class Validator
{
    /** @var array<string,mixed> */
    protected array $data;
    /** @var array<string,string|array<int,string>> */
    protected array $rules;
    /** @var array<string,array<int,string>> */
    protected array $errors = [];
    /** @var array<string,string> */
    protected array $fieldTranslations = [
        'name' => 'namn',
        'first_name' => 'förnamn',
        'last_name' => 'efternamn',
        'email' => 'e-post',
        'message' => 'meddelande',
        'password' => 'lösenord',
        'password_confirmation' => 'repetera lösenord',
        'honeypot' => 'honeypot',
        'category' => 'kategori',
        'description' => 'beskrivning',
    ];

    /**
     * @param array<string,mixed>                           $data
     * @param array<string,string|array<int,string>>        $rules
     */
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
     * @return array<string,array<int,string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function addError(string $field, string $message, bool $includeValue = false): void
    {
        $value = $this->data[$field] ?? null;

        if ($includeValue && $value !== null) {
            if (is_scalar($value)) {
                $valueString = (string) $value;
            } else {
                // För icke-skalära värden (array/objekt) visar vi inget konkret värde
                $valueString = '';
            }

            $message = str_replace(
                '{placeholder}',
                htmlspecialchars($valueString, ENT_QUOTES),
                $message
            );
        }

        $this->errors[$field][] = $message;
    }

    // Hjälpmetod att generera nytt honeypot-id efter validering
    /**
     * @param callable():string $generator
     */
    public function regenerateHoneypotId(callable $generator): string
    {
        $newId = $generator();

        if (!is_string($newId)) {
            throw new \RuntimeException('Honeypot ID generator must return a string.');
        }

        $_SESSION['honeypot_id'] = $newId;

        return $newId;
    }

    protected function applyRule(string $field, string $rule, mixed $value): void
    {
        if (str_contains($rule, ':')) {
            [$rule, $parameter] = explode(':', $rule, 2);
        } else {
            $parameter = null;
        }

        // Hämta värdet med dot-notation
        $value = $this->getValueForDotNotation($field);

        // Om fältet är nullable och värdet är null eller saknas
        if ($rule === 'nullable' && (is_null($value) || $value === '' || (is_array($value) && $value['error'] === UPLOAD_ERR_NO_FILE))) {
            return; // Ignorera helt
        }

        // Om regeln är 'sometimes', ignorera fältet om det saknas
        if ($rule === 'sometimes') {
            if (!array_key_exists($field, $this->data)) {
                return; // Ignorera
            }
        }

        // Dynamiskt valideringsmetodnamn
        $method = 'validate' . str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($rule))));

        // Kontrollera att metoden finns
        if (!method_exists($this, $method)) {
            throw new InvalidArgumentException("Valideringsregeln '$rule' stöds inte.");
        }

        // Hantering för regeln 'confirmed'
        if ($rule === 'confirmed') {
            $parameter = rtrim($field, '_confirmation'); // Hitta huvudfältet som konfirmeras
        }

        // Extra logik för filbaserade valideringar
        if (in_array($rule, ['file_type', 'file_size']) && isset($this->data[$field])) {
            $value = $this->data[$field];
        }

        // Applicera valideringen och hantera fel
        if (!$this->$method($value, $parameter)) {
            $this->addError($field, $this->getErrorMessage($field, $rule, $parameter));
            // Särskild hantering: om honeypot_dynamic faller, lägg ett generellt formulärfel
            if ($rule === 'honeypot_dynamic') {
                $this->addError('form-error', 'Det verkar som att du försöker skicka spam. Försök igen.');
            }
        }
    }

    protected function getErrorMessage(string $field, string $rule, mixed $parameter = null): string
        {
        // Översätt huvudfältet
        $translatedField = $this->fieldTranslations[str_replace('hp_', 'honeypot', $field)] ?? $field;

        // Normalisera parameter till sträng (för string-interpolation) eller null
        $parameterString = null;
        if ($parameter !== null) {
            if (is_scalar($parameter)) {
                $parameterString = (string) $parameter;
            } else {
                $parameterString = '';
            }
        }

        if ($rule === 'confirmed') {
            // Korrekt huvudfält för "_confirmation"-fält
            $parameterField = $parameterString ?? rtrim($field, '_confirmation');
            $translatedParameter = $this->fieldTranslations[$parameterField] ?? $parameterField;

            return "Fältet $translatedField måste matcha fältet $translatedParameter.";
        }

        // Översätt parameterfältet, t.ex. password i regeln 'confirmed'
        $translatedParameter = $parameterString !== null
            ? ($this->fieldTranslations[$parameterString] ?? $parameterString)
            : null;

        // Standardfelmeddelanden
        $messages = [
            'required' => "Fältet $translatedField är obligatoriskt.",
            'email' => "Fältet $translatedField måste vara en giltig e-postadress.",
            'min' => "Fältet $translatedField måste vara minst $parameterString tecken långt.",
            'max' => "Fältet $translatedField får inte vara längre än $parameterString tecken.",
            'numeric' => "Fältet $translatedField måste vara numeriskt.",
            'integer' => "Fältet $translatedField måste vara ett giltigt heltal.",
            'alphanumeric' => "Fältet $translatedField får endast innehålla bokstäver och siffror.",
            'match' => "Fältet $translatedField måste matcha fältet $translatedParameter.",
            'honeypot' => "Spam.",
            'honeypot_dynamic' => "Spam.",
            'unique' => "Fältet $translatedField måste vara unikt, '{placeholder}' används redan.",
            'regex' => "Fältet $translatedField har ett ogiltigt format.",
            'in' => "Fältet $translatedField måste vara ett av följande värden: $parameterString.",
            'not_in' => "Fältet $translatedField får inte vara ett av följande värden: $parameterString.",
            'boolean' => "Fältet $translatedField måste vara sant eller falskt.",
            'confirmed' => "Fältet $translatedField måste matcha fältet $translatedParameter.",
            'date' => "Fältet $translatedField måste vara ett giltigt datum.",
            'date_format' => "Fältet $translatedField måste vara i formatet '$parameterString'.",
            'starts_with' => "Fältet $translatedField måste börja med ett av följande: $parameterString.",
            'ends_with' => "Fältet $translatedField måste sluta med ett av följande: $parameterString.",
            'ip' => "Fältet $translatedField måste vara en giltig IP-adress.",
            'url' => "Fältet $translatedField måste vara en giltig URL.",
            'required_with' => "Fältet $translatedField krävs när $translatedParameter anges.",
            'nullable' => "Fältet $translatedField får lämnas tomt, men om det anges måste det uppfylla valideringsreglerna.",
            'string' => "Fältet $translatedField måste vara en giltig textsträng.",
            'file_type' => "Fältet $translatedField måste vara av typen: $parameterString.",
            'file_size' => "Fältet $translatedField får inte överstiga $parameterString MB.",
        ];

        $message = $messages[$rule] ?? "Fältet $translatedField uppfyller inte valideringsregeln '$rule'.";

        // Om värdet finns, ersätt {placeholder} i felmeddelandet
        $value = $this->data[$field] ?? null;
        if (str_contains($message, '{placeholder}') && $value !== null) {
            if (is_scalar($value)) {
                $valueString = (string) $value;
            } else {
                $valueString = '';
            }

            $message = str_replace(
                '{placeholder}',
                htmlspecialchars($valueString, ENT_QUOTES),
                $message
            );
        }

        return $message;
    }

    protected function validateInteger(mixed $value, ?string $parameter = null): bool
    {
        // Kontrollera om värdet är ett giltigt heltal eller en sträng som kan konverteras till ett heltal
        return is_int($value) || (is_string($value) && ctype_digit($value));
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
        // Säkerställ att parametern är numerisk
        if (!is_numeric($parameter)) {
            throw new InvalidArgumentException("Valideringsregeln 'min' kräver en numerisk parameter.");
        }

        $minValue = (float)$parameter;

        // Om värdet är null (nullable) eller en tom sträng, returnera alltid true
        if (is_null($value) || $value === '') {
            return true;
        }

        // Kontrollera numeriska värden
        if (is_numeric($value)) {
            return (float)$value >= $minValue;
        }

        // Kontrollera längden för strängvärden
        if (is_string($value)) {
            return mb_strlen($value) >= (int)$minValue;
        }

        // Om det inte är numeriskt eller en sträng, valideringen misslyckas
        return false;
    }

    protected function validateUrl(mixed $value, ?string $parameter = null): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    protected function validateMax(mixed $value, ?string $parameter = null): bool
    {
        // Säkerställ att parametern är numerisk
        if (!is_numeric($parameter)) {
            throw new InvalidArgumentException("Valideringsregeln 'max' kräver en numerisk parameter.");
        }

        $maxValue = (float)$parameter;

        // Om värdet är null (nullable) eller en tom sträng, returnera alltid true
        if (is_null($value) || $value === '') {
            return true;
        }

        // Kontrollera numeriska värden
        if (is_numeric($value)) {
            return (float)$value <= $maxValue;
        }

        // Kontrollera längden för strängvärden
        if (is_string($value)) {
            return mb_strlen($value) <= (int)$maxValue;
        }

        // Om det inte är numeriskt eller en sträng, valideringen misslyckas
        return false;
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

        if (!is_scalar($value)) {
            return false;
        }

        /** @var string $subject */
        $subject = (string) $value;

        return preg_match($parameter, $subject) === 1;
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

        if (!is_scalar($value)) {
            return false;
        }

        /** @var string $valueString */
        $valueString = (string) $value;

        return in_array($valueString, $allowedValues, true);
    }

    protected function validateHoneypot(mixed $value, ?string $parameter = null): bool
    {
        return empty($value); // Ett giltigt honeypot-fält bör vara tomt
    }

    // Dynamisk honeypot: fältets namn är den förväntade honeypot-nyckeln i sessionen
    protected function validateHoneypotDynamic(mixed $value, ?string $parameter = null): bool
    {
        $expected = $_SESSION['honeypot_id'] ?? null;
        if (!$expected || !is_string($expected) || !str_starts_with($expected, 'hp_')) {
            // Om inget förväntat id finns, betrakta som fel (hårdare hållning)
            return false;
        }

        // Fältet måste finnas och heta exakt som expected
        if (!array_key_exists($expected, $this->data)) {
            return false;
        }

        // Värdet måste vara tomt
        $submitted = $this->data[$expected] ?? null;
        return $submitted === '' || $submitted === null;
    }

    protected function validateNotIn(mixed $value, ?string $parameter = null): bool
    {
        if ($parameter === null) {
            throw new InvalidArgumentException("Valideringsregeln 'not_in' kräver en lista med otillåtna värden.");
        }

        $disallowedValues = explode(',', $parameter);

        if (!is_scalar($value)) {
            return true;
        }

        /** @var string $valueString */
        $valueString = (string) $value;

        return !in_array($valueString, $disallowedValues, true);
    }

    protected function validateBoolean(mixed $value, ?string $parameter = null): bool
    {
        if (is_bool($value)) {
            return true;
        }

        if (!is_scalar($value)) {
            return false;
        }

        /** @var string $valueString */
        $valueString = (string) $value;

        return in_array($valueString, ['true', 'false', '1', '0'], true);
    }
    
    protected function validateDate(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if (!is_scalar($value)) {
            return false;
        }

        /** @var string $valueString */
        $valueString = (string) $value;

        return strtotime($valueString) !== false;
    }

    protected function validateUnique(mixed $value, string $parameter): bool
    {
        if ($parameter === '') {
            throw new InvalidArgumentException("Valideringsregeln 'unique' kräver en parametersträng.");
        }

        $parts = explode(',', $parameter);
        $modelClass = $parts[0] ?? null;
        $column = $parts[1] ?? null;
        $excludeId = isset($parts[2]) ? intval(explode('=', $parts[2])[1]) : null;

        if (!is_string($modelClass) || $modelClass === '' || !class_exists($modelClass)) {
            throw new InvalidArgumentException(
                "Valideringsregeln 'unique' kräver en giltig modellklass. Kontrollera att '$modelClass' existerar."
            );
        }

        if (!is_subclass_of($modelClass, \Radix\Database\ORM\Model::class)) {
            throw new InvalidArgumentException(
                "Valideringsregeln 'unique' kräver att modellen ärver " . \Radix\Database\ORM\Model::class . "."
            );
        }

        if ($column === null || $column === '') {
            throw new InvalidArgumentException("Valideringsregeln 'unique' kräver att kolumn specificeras.");
        }

        /** @var class-string<\Radix\Database\ORM\Model> $modelClass */

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

        if (!is_scalar($value)) {
            return false;
        }

        /** @var string $valueString */
        $valueString = (string) $value;

        $date = \DateTime::createFromFormat($parameter, $valueString);

        return $date && $date->format($parameter) === $valueString;
    }

    protected function validateStartsWith(mixed $value, ?string $parameter = null): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if ($parameter === null) {
            throw new InvalidArgumentException("Valideringsregeln 'starts_with' kräver en lista med prefix.");
        }

        if (!is_scalar($value)) {
            return false;
        }

        /** @var string $valueString */
        $valueString = (string) $value;

        $prefixes = explode(',', $parameter);

        foreach ($prefixes as $prefix) {
            if (str_starts_with($valueString, $prefix)) {
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

        if (!is_scalar($value)) {
            return false;
        }

        /** @var string $valueString */
        $valueString = (string) $value;

        $suffixes = explode(',', $parameter);

        foreach ($suffixes as $suffix) {
            if (str_ends_with($valueString, $suffix)) {
                return true;
            }
        }

        return false;
    }

    protected function validateConfirmed(mixed $value, string $parameter): bool
    {
        // Extra säkerhet om någon skulle anropa felaktigt utanför applyRule
        if ($parameter === '') {
            throw new InvalidArgumentException("Valideringsregeln 'confirmed' kräver ett huvudfält.");
        }

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
        if (is_array($value) && isset($value['error']) && $value['error'] === UPLOAD_ERR_NO_FILE) {
            return true; // Anses validerad om ingen fil skickades
        }

        // Kontrollera att vi har en fil-array med 'type'
        if (!is_array($value) || !array_key_exists('type', $value)) {
            return false;
        }

        $type = $value['type'];

        // Säkerställ att type är en sträng
        if (!is_string($type) || $type === '') {
            return false;
        }

        // Kontrollera att parametern finns och inte är tom
        if ($parameter === null || $parameter === '') {
            throw new InvalidArgumentException("Parameter krävs för 'file_type'-regeln.");
        }

        // Tillåtna MIME-typer
        $allowedTypes = array_map('trim', explode(',', strtolower($parameter)));

        // Kontrollera att filens MIME-typ är tillåten
        return in_array(strtolower($type), $allowedTypes, true);
    }

    protected function validateFileSize(mixed $value, ?string $parameter = null): bool
    {
        // Ignorera validering om fältet är "nullable" och ingen fil skickades
        if (is_array($value) && isset($value['error']) && $value['error'] === UPLOAD_ERR_NO_FILE) {
            return true; // Anses validerad om ingen fil skickades
        }

        // Kontrollera om arrayen är korrekt
        if (!is_array($value) || empty($value['size'])) {
            return false;
        }

        // Kontrollera att parametern är en giltig siffra
        if ($parameter === null || !is_numeric($parameter)) {
            throw new InvalidArgumentException("Parameter för 'file_size' måste vara en giltig siffra.");
        }

        // Max tillåtna filstorlek i bytes
        $maxBytes = (int) $parameter * 1024 * 1024;

        // Kontrollera om filens storlek ligger inom det tillåtna intervallet
        return $value['size'] <= $maxBytes;
    }

    protected function getValueForDotNotation(string $field): mixed
    {
        // Om fältet inte innehåller en punkt (.), returnera direkt från `$this->data`
        if (!str_contains($field, '.')) {
            return $this->data[$field] ?? null;
        }

        // Dela upp fältet baserat på punkter
        $keys = explode('.', $field);
        $value = $this->data;

        foreach ($keys as $key) {
            // Kontrollera om nyckeln existerar i den aktuella nivån
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null; // Om någon del saknas, returnera null
            }
            $value = $value[$key]; // Navigera ner till nästa nivå
        }

        return $value;
    }

    // Konvertera bytes till MB
    protected function convertSizeToMB(int $sizeInBytes): float
    {
        return round((float)$sizeInBytes / (1024 * 1024), 2, PHP_ROUND_HALF_UP);
    }
}