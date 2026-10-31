<?php

namespace App\Http\Requests\Team;

use App\Models\Management\Team;
use App\Models\Management\TeamDomain;
use App\Support\EmailDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTeamDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('domain')) {
                    return;
                }

                $domain = $this->normalizedDomain();

                if ($domain === null) {
                    $validator->errors()->add('domain', __('Enter a domain like example.com.'));

                    return;
                }

                if (EmailDomain::isPublicProvider($domain)) {
                    $validator->errors()->add('domain', __('Public email providers cannot be claimed by a team.'));

                    return;
                }

                /** @var Team $team */
                $team = $this->route('team');
                $existing = TeamDomain::query()->where('domain', $domain);

                if ((clone $existing)->where('team_id', $team->id)->exists()) {
                    $validator->errors()->add('domain', __('This domain has already been added.'));
                } elseif ($existing->whereNotNull('verified_at')->exists()) {
                    $validator->errors()->add('domain', __('This domain is already verified by another team.'));
                }
            },
        ];
    }

    /** The domain as stored: lowercase ASCII, or null when invalid. */
    public function normalizedDomain(): ?string
    {
        return EmailDomain::normalize($this->string('domain')->toString());
    }
}
