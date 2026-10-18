<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Enums\SocialProvider;
use App\Modules\HR\Recruitment\Models\SocialAccount;
use App\Modules\HR\Recruitment\Models\SocialPost;
use App\Modules\HR\Recruitment\Models\Vacancy;
use App\Modules\HR\Recruitment\Enums\VacancyStatus;
use App\Modules\HR\Recruitment\Services\SocialPublisherManager;
use App\Support\Social\SocialNetworkPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SocialAccountController extends Controller
{
    public function index(): Response
    {
        $publishable = array_values(array_intersect(
            SocialNetworkPublisher::SUPPORTED,
            array_column(SocialProvider::cases(), 'value'),
        ));

        $accounts = SocialAccount::orderBy('provider')->orderBy('display_name')->get()
            ->map(fn (SocialAccount $a) => [
                'id'               => $a->id,
                'uuid'             => $a->uuid,
                'provider'         => $a->provider->value,
                'provider_label'   => $a->provider->label(),
                'display_name'     => $a->display_name,
                'is_active'        => $a->is_active,
                'has_token'        => !empty($a->getRawOriginal('access_token')),
                'token_expires_at' => $a->token_expires_at?->toDateTimeString(),
            ]);

        return Inertia::render('Modules/Recruitment/Settings/SocialAccounts/Index', [
            'accounts'  => $accounts,
            'providers' => SocialProvider::options(),
            // Which providers this installation can actually publish to, and which of those
            // the tenant can connect by signing in rather than pasting a token. Both were
            // absent before, so the screen offered a publish button for providers that had
            // no integration at all.
            'publishable' => $publishable,
            // The publish endpoint existed with nothing in the interface calling it, so a
            // connected account had no way to be used. Only externally published vacancies
            // are offered: an advert needs a public application page to link to.
            'vacancies' => Vacancy::query()
                ->where('status', VacancyStatus::External->value)
                ->whereNotNull('application_token')
                ->orderByDesc('published_externally_at')
                ->limit(100)
                ->get(['id', 'title', 'vacancy_number'])
                ->map(fn (Vacancy $v) => [
                    'id'    => $v->id,
                    'label' => trim("{$v->vacancy_number} · {$v->title}", ' ·'),
                ]),
            // No OAuth sign-in exchange ships with this product, so accounts are connected by
            // pasting a token.
            'connectable' => [],
        ]);
    }

    public function connect(string $provider): RedirectResponse
    {
        return back()->with('error', 'Signing in to '.ucfirst($provider).' is not available — add the account with an access token instead.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'provider'     => ['required', 'string', 'in:' . implode(',', array_column(SocialProvider::cases(), 'value'))],
            'display_name' => ['required', 'string', 'max:150'],
            'is_active'    => ['nullable', 'boolean'],
        ]);

        SocialAccount::create([
            'provider'     => $data['provider'],
            'display_name' => $data['display_name'],
            'is_active'    => $data['is_active'] ?? true,
            'created_by'   => $request->user()->id,
        ]);

        return back()->with('success', 'Social account added. Use Connect to sign in to it.');
    }

    public function update(Request $request, SocialAccount $socialAccount): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:150'],
            'is_active'    => ['nullable', 'boolean'],
        ]);

        $socialAccount->update($data);

        return back()->with('success', 'Social account updated.');
    }

    public function destroy(SocialAccount $socialAccount): RedirectResponse
    {
        $socialAccount->delete();

        return back()->with('success', 'Social account removed.');
    }

    public function publish(Request $request, SocialAccount $socialAccount, SocialPublisherManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'vacancy_id' => ['required', 'integer', 'exists:recruitment_vacancies,id'],
        ]);

        $vacancy = Vacancy::findOrFail($data['vacancy_id']);

        if (! $vacancy->application_token) {
            return back()->with('error', 'That vacancy has no public application page yet — publish it externally first.');
        }

        $post = $manager->for($socialAccount->provider)->publish($vacancy, $socialAccount);

        if ($post->status === 'failed') {
            return back()->with('error', $post->error_message);
        }

        return back()->with('success', 'Vacancy published to ' . $socialAccount->provider->label() . '.');
    }
}
