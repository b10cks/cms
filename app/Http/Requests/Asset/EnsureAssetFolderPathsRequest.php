<?php

namespace App\Http\Requests\Asset;

use App\Models\Space\AssetFolder;
use App\Rules\BoundedString;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnsureAssetFolderPathsRequest extends FormRequest
{
    /**
     * A cheap first gate on the payload size. It is not what bounds the work:
     * see MAX_SEGMENTS.
     */
    public const MAX_PATHS = 2000;

    /**
     * One path is a whole `a/b/c` chain. 1000 characters is far past anything a
     * file system hands a browser and stops a single string from carrying
     * thousands of segments.
     */
    public const MAX_PATH_LENGTH = 1000;

    /**
     * The number that actually bounds a request. Every segment is a purifier
     * pass and, in the worst case, a folder create with its own insert,
     * broadcast and audit row. Measured at 2.4 ms per newly created folder, so a
     * payload at this cap where nothing exists yet is ~4.8 s of work. That sits
     * inside the action's 30 s lock TTL with room for production being several
     * times slower, and far short of the 59 s `max_execution_time` that would
     * kill the request mid-transaction.
     *
     * The array size alone cannot bound this: one 1000-character path can carry
     * 500 segments, so 2000 paths could mean a million folder creates.
     */
    public const MAX_SEGMENTS = 2000;

    public function rules(): array
    {
        return [
            'parent_id' => [
                'nullable',
                'string',
                Rule::exists(new AssetFolder()->getConnectionName() . '.asset_folders', 'id')
                    ->whereNull('deleted_at'),
            ],
            'paths' => 'required|array|min:1|max:' . self::MAX_PATHS,
            // A folder named "   " is a folder the user really dropped, so a
            // blank path must not be rejected. `string|min|max` cannot express
            // that: Laravel skips non-implicit rules on a blank string, which
            // would drop the length bound with it. See BoundedString.
            'paths.*' => [new BoundedString(1, self::MAX_PATH_LENGTH)],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $paths = $this->input('paths');

                if (!is_array($paths)) {
                    return;
                }

                $segments = 0;

                foreach ($paths as $path) {
                    if (is_string($path)) {
                        $segments += count(array_filter(
                            explode('/', $path),
                            static fn (string $segment): bool => $segment !== '',
                        ));
                    }
                }

                if ($segments > self::MAX_SEGMENTS) {
                    $validator->errors()->add(
                        'paths',
                        'A single upload can mirror at most ' . self::MAX_SEGMENTS
                        . ' folder levels, this drop has ' . $segments . '. Split it into smaller parts.',
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'paths.max' => 'A single upload can mirror at most ' . self::MAX_PATHS . ' folders. Split the drop into smaller parts.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
