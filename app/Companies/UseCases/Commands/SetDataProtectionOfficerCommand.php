<?php

namespace App\Companies\UseCases\Commands;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use Illuminate\Validation\ValidationException;
use App\Foundation\AuditTrail\Values\FieldChanges;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Companies\Entities\CompanyDataProtectionOfficer;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use App\Companies\Memberships\UseCases\Casts\SriLankanE164Cast;
use App\Companies\Memberships\UseCases\Rules\SriLankanPhoneNumber;

/**
 * The company's data protection officer as the owner or an admin sends it (UC-1-146) —
 * a replace: all three fields each time, a phone left out cleared. The company and the
 * actor are server-sourced and carry no rule; any other key is refused, so a mistyped
 * field cannot silently clear a stored one.
 */
class SetDataProtectionOfficerCommand extends Data
{
    /** @var list<string> */
    protected const array EDITABLE_FIELDS = ['name', 'email', 'phone'];

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    #[WithCast(ModelCast::class, User::class)]
    public User $setBy;

    public string $name;

    public string $email;

    #[WithCast(SriLankanE164Cast::class)]
    public ?string $phone = null;

    public static function fromRequest(Request $request): static
    {
        $unknownKeys = array_diff(array_keys($request->all()), self::EDITABLE_FIELDS);

        if ($unknownKeys !== []) {
            throw ValidationException::withMessages(
                array_fill_keys($unknownKeys, 'This field cannot be updated.')
            );
        }

        $request->merge(['company' => $request->company(), 'setBy' => $request->user()]);

        return static::validateAndCreate($request->all());
    }

    /**
     * Each field the set changes, old -> new — every field is stored encrypted, so every
     * one is encrypted in the change.
     */
    public function changesFrom(?CompanyDataProtectionOfficer $officer): FieldChanges
    {
        $sent = ['name' => $this->name, 'email' => $this->email, 'phone' => $this->phone];

        return FieldChanges::between($officer?->contact() ?? [], $sent, array_keys($sent));
    }

    /**
     * @return array<string, array<int, string|ValidationRule>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255', new SriLankanPhoneNumber],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'name' => 'data protection officer name',
            'email' => 'data protection officer email',
            'phone' => 'data protection officer phone',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['email.email' => 'The data protection officer email must be a valid email address.'];
    }
}
