<?php

namespace JoelButcher\Socialstream\Tests\Feature;

use App\Actions\Socialstream\CreateConnectedAccount;
use App\Actions\Socialstream\CreateUserFromProvider;
use App\Actions\Socialstream\GenerateRedirectForProvider;
use App\Actions\Socialstream\HandleInvalidState;
use App\Actions\Socialstream\ResolveSocialiteUser;
use App\Actions\Socialstream\SetUserPassword;
use App\Actions\Socialstream\UpdateConnectedAccount;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use JoelButcher\Socialstream\Features;
use JoelButcher\Socialstream\Socialstream;
use JoelButcher\Socialstream\Tests\TestCase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\SocialiteServiceProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery as m;

uses(WithFaker::class, RefreshDatabase::class);

beforeEach(function () {
    Config::set('jetstream.stack', 'livewire');
    Config::set('jetstream.features', []);

    Config::set('services.github', [
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
        'redirect' => 'https://example.test/oauth/github/callback',
    ]);

    Socialstream::resolvesSocialiteUsersUsing(ResolveSocialiteUser::class);
    Socialstream::createUsersFromProviderUsing(CreateUserFromProvider::class);
    Socialstream::createConnectedAccountsUsing(CreateConnectedAccount::class);
    Socialstream::updateConnectedAccountsUsing(UpdateConnectedAccount::class);
    Socialstream::setUserPasswordsUsing(SetUserPassword::class);
    Socialstream::handlesInvalidStateUsing(HandleInvalidState::class);
    Socialstream::generatesProvidersRedirectsUsing(GenerateRedirectForProvider::class);
});

it('redirects users', function (): void
{
    $response = $this->get(route('oauth.redirect', 'github'));

    $response->assertRedirect()
        ->assertRedirectContains('github.com');
});

test('users can register', function (): void
{
    $user = (new SocialiteUser())
        ->map([
            'id' => $githubId = $this->faker->numerify('########'),
            'nickname' => 'joel',
            'name' => 'Joel',
            'email' => 'joel@socialstream.dev',
            'avatar' => null,
            'avatar_original' => null,
        ])
        ->setToken('user-token')
        ->setRefreshToken('refresh-token')
        ->setExpiresIn(3600);

    $provider = m::mock(GithubProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($user);

    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    session()->put('socialstream.previous_url', route('register'));

    $this->get(route('oauth.callback', 'github'))
        ->assertRedirect('/home');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', ['email' => 'joel@socialstream.dev']);
    $this->assertDatabaseHas('connected_accounts', [
        'provider' => 'github',
        'provider_id' => $githubId,
        'email' => 'joel@socialstream.dev',
    ]);
});

test('existing users can login', function (): void
{
    $user = User::create([
        'name' => 'Joel Butcher',
        'email' => 'joel@socialstream.dev',
        'password' => Hash::make('password'),
    ]);

    $user->connectedAccounts()->create([
        'provider' => 'github',
        'provider_id' => $githubId = $this->faker->numerify('########'),
        'email' => 'joel@socialstream.dev',
        'token' => Str::random(64),
    ]);

    $this->assertDatabaseHas('users', ['email' => 'joel@socialstream.dev']);
    $this->assertDatabaseHas('connected_accounts', [
        'provider' => 'github',
        'provider_id' => $githubId,
        'email' => 'joel@socialstream.dev',
    ]);

    $user = (new SocialiteUser())
        ->map([
            'id' => $githubId,
            'nickname' => 'joel',
            'name' => 'Joel',
            'email' => 'joel@socialstream.dev',
            'avatar' => null,
            'avatar_original' => null,
        ])
        ->setToken('user-token')
        ->setRefreshToken('refresh-token')
        ->setExpiresIn(3600);

    $provider = m::mock(GithubProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($user);

    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    $this->get(route('oauth.callback', 'github'))
        ->assertRedirect('/home');

    $this->assertAuthenticated();
});

test('authenticated users can link to provider', function (): void
{
    $this->actingAs(User::create([
        'name' => 'Joel Butcher',
        'email' => 'joel@socialstream.dev',
        'password' => Hash::make('password'),
    ]));

    $this->assertDatabaseHas('users', ['email' => 'joel@socialstream.dev']);
    $this->assertDatabaseEmpty('connected_accounts');

    $user = (new SocialiteUser())
        ->map([
            'id' => $githubId = $this->faker->numerify('########'),
            'nickname' => 'joel',
            'name' => 'Joel',
            'email' => 'joel@socialstream.dev',
            'avatar' => null,
            'avatar_original' => null,
        ])
        ->setToken('user-token')
        ->setRefreshToken('refresh-token')
        ->setExpiresIn(3600);

    $provider = m::mock(GithubProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($user);

    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    $this->get(route('oauth.callback', 'github'))
        ->assertRedirect('/user/profile');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('connected_accounts', [
        'provider' => 'github',
        'provider_id' => $githubId,
        'email' => 'joel@socialstream.dev',
    ]);
});

test('new users can register from login page', function (): void
{
    Config::set('socialstream.features', [
        Features::createAccountOnFirstLogin(),
    ]);

    $this->assertDatabaseEmpty('users');
    $this->assertDatabaseEmpty('connected_accounts');

    $user = (new SocialiteUser())
        ->map([
            'id' => $githubId = $this->faker->numerify('########'),
            'nickname' => 'joel',
            'name' => 'Joel',
            'email' => 'joel@socialstream.dev',
            'avatar' => null,
            'avatar_original' => null,
        ])
        ->setToken('user-token')
        ->setRefreshToken('refresh-token')
        ->setExpiresIn(3600);

    $provider = m::mock(GithubProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($user);

    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    $this->get(route('oauth.callback', 'github'))
        ->assertRedirect('/home');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('connected_accounts', [
        'provider' => 'github',
        'provider_id' => $githubId,
        'email' => 'joel@socialstream.dev',
    ]);
});

test('users can login on registration', function (): void
{
    Config::set('socialstream.features', [
        Features::loginOnRegistration(),
    ]);

    User::create([
        'name' => 'Joel Butcher',
        'email' => 'joel@socialstream.dev',
        'password' => Hash::make('password'),
    ]);

    $this->assertDatabaseHas('users', ['email' => 'joel@socialstream.dev']);
    $this->assertDatabaseEmpty('connected_accounts');

    $user = (new SocialiteUser())
        ->map([
            'id' => $githubId = $this->faker->numerify('########'),
            'nickname' => 'joel',
            'name' => 'Joel',
            'email' => 'joel@socialstream.dev',
            'avatar' => null,
            'avatar_original' => null,
        ])
        ->setToken('user-token')
        ->setRefreshToken('refresh-token')
        ->setExpiresIn(3600);

    $provider = m::mock(GithubProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($user);

    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    session()->put('socialstream.previous_url', route('register'));

    $this->get(route('oauth.callback', 'github'))
        ->assertRedirect('/home');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('connected_accounts', [
        'provider' => 'github',
        'provider_id' => $githubId,
        'email' => 'joel@socialstream.dev',
    ]);
});

it('generates_missing_emails', function (): void
{
    Config::set('socialstream.features', [
        Features::generateMissingEmails(),
    ]);

    $user = (new SocialiteUser())
        ->map([
            'id' => $githubId = $this->faker->numerify('########'),
            'nickname' => 'joel',
            'name' => 'Joel',
            'avatar' => null,
            'avatar_original' => null,
        ])
        ->setToken('user-token')
        ->setRefreshToken('refresh-token')
        ->setExpiresIn(3600);

    $provider = m::mock(GithubProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($user);

    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    session()->put('socialstream.previous_url', route('register'));

    $this->get(route('oauth.callback', 'github'))
        ->assertRedirect('/home');

    $user = User::first();

    $this->assertAuthenticated();
    $this->assertEquals("$githubId@github", $user->email);
    $this->assertDatabaseHas('connected_accounts', [
        'provider' => 'github',
        'provider_id' => $githubId,
        'email' => $user->email,
    ]);
});
