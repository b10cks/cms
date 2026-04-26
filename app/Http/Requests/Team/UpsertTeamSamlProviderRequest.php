<?php

namespace App\Http\Requests\Team;

use App\Enums\TeamRoleKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertTeamSamlProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'idp_entity_id' => ['required', 'string', 'max:512'],
            'sso_url' => ['required', 'url', 'max:1024'],
            'slo_url' => ['nullable', 'url', 'max:1024'],
            'idp_x509_cert' => ['required', 'string', 'max:20000'],
            'sp_x509_cert' => ['nullable', 'string', 'max:20000'],
            'sp_private_key' => ['nullable', 'string', 'max:20000'],
            'name_id_format' => ['required', 'string', 'max:255'],
            'attribute_mapping' => ['required', 'array'],
            'attribute_mapping.email' => ['required', 'string', 'max:255'],
            'attribute_mapping.first_name' => ['nullable', 'string', 'max:255'],
            'attribute_mapping.last_name' => ['nullable', 'string', 'max:255'],
            'attribute_mapping.external_id' => ['nullable', 'string', 'max:255'],
            'role_attribute' => ['nullable', 'string', 'max:255'],
            'role_mapping' => ['nullable', 'array'],
            'role_mapping.*' => ['required', 'string', Rule::in(TeamRoleKey::values())],
            'default_role' => ['required', 'string', Rule::in(TeamRoleKey::values())],
            'allow_jit' => ['required', 'boolean'],
            'strict' => ['required', 'boolean'],
            'sign_authn_requests' => ['required', 'boolean'],
            'sign_logout_requests' => ['required', 'boolean'],
            'want_assertions_signed' => ['required', 'boolean'],
            'want_messages_signed' => ['required', 'boolean'],
            'want_assertions_encrypted' => ['required', 'boolean'],
            'digest_algorithm' => ['required', 'string', 'max:255'],
            'signature_algorithm' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['idp_x509_cert', 'sp_x509_cert'] as $field) {
                    $value = $this->input($field);
                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    if (! @openssl_x509_read($this->normalizeCertificate($value))) {
                        $validator->errors()->add($field, 'The certificate must be a valid X.509 PEM certificate.');
                    }
                }

                $privateKey = $this->input('sp_private_key');
                if (is_string($privateKey) && $privateKey !== '' && ! @openssl_pkey_get_private($privateKey)) {
                    $validator->errors()->add('sp_private_key', 'The private key must be a valid PEM private key.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'allow_jit' => $this->boolean('allow_jit', true),
            'strict' => $this->boolean('strict', true),
            'sign_authn_requests' => $this->boolean('sign_authn_requests'),
            'sign_logout_requests' => $this->boolean('sign_logout_requests'),
            'want_assertions_signed' => $this->boolean('want_assertions_signed', true),
            'want_messages_signed' => $this->boolean('want_messages_signed'),
            'want_assertions_encrypted' => $this->boolean('want_assertions_encrypted'),
        ]);
    }

    private function normalizeCertificate(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '-----BEGIN')) {
            return $value;
        }

        $body = trim(str_replace(["\r", "\n", ' '], '', $value));

        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split($body, 64, "\n")
            .'-----END CERTIFICATE-----';
    }
}
