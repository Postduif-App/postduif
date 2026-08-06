<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInstallationRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Nobody is signed in here and there is nothing yet to have rights over.
     * What stands in for authorisation is EnsureInstallationIsPending on the
     * route, which is a question about the platform rather than about a person.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),

            /*
             * The same rule the workspace form uses, and only a name: the
             * address is derived from it — see CreateWorkspace — because a slug
             * is a thing somebody has to be taught to care about, and the very
             * first screen of a new platform is the worst place to teach it.
             *
             * The unique e-mail rule above is also what makes a double-submitted
             * form harmless: the second one fails validation rather than
             * quietly appointing a second moderator.
             */
            'workspace' => ['required', 'string', 'max:60'],
        ];
    }

    /**
     * What the installer needs, in the shape the action asks for.
     *
     * Spelled out rather than handed validated() straight through: the rules
     * above are what make every one of these a string, and reading them off one
     * by one is what carries that as far as the action rather than stopping at
     * an array of mixed.
     *
     * @return array{name: string, email: string, password: string, workspace: string}
     */
    public function fields(): array
    {
        return [
            'name' => (string) $this->string('name'),
            'email' => (string) $this->string('email'),
            'password' => (string) $this->string('password'),
            'workspace' => (string) $this->string('workspace'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'workspace.required' => __('requests.installation.workspace_required'),
        ];
    }
}
