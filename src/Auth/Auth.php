<?php

declare(strict_types=1);

namespace Radix\Auth;

use App\Models\User;
use Radix\Session\Session;
use Radix\Session\SessionInterface;

readonly class Auth
{
    public function __construct(private SessionInterface $session)
    {
    }

    private function confirmIsValid(): void
    {
        if (!$this->session->isValid()) {
            $this->session->destroy();

            // flash message

            redirect(route('auth.login.index'));
        }
    }

    public function login(int $user_id): void
    {
        session_regenerate_id(true);

        $this->session->set('ip', $_SERVER['REMOTE_ADDR']);
        $this->session->set('user_agent', $_SERVER['HTTP_USER_AGENT']);
        $this->session->set('last_login', time());

        $this->confirmIsValid();
        $this->session->set(Session::AUTH_KEY, $user_id);
        $this->updateInactiveUsers();

        $this->setOnlineStatus($user_id);
    }

    /**
     * Logga ut användaren och markera som offline.
     */
    public function logout(): void
    {
        $user_id = $this->session->get(Session::AUTH_KEY);

        if ($user_id) {
            $this->setOfflineStatus($user_id); // Uppdatera offline-status
        }

        $this->session->destroy(); // Förstör sessionen
    }

    private function setOnlineStatus(int $user_id): void
    {
        $user = User::find($user_id); // Hämta användaren
        $user?->setOnline(); // Markera som online
    }

    private function setOfflineStatus(int $user_id): void
    {
        $user = User::find($user_id); // Hämta användaren
        $user?->setOffline(); // Markera som offline
    }

    private function updateInactiveUsers(): void
    {
        $timeout = 15 * 60; // 15 minuter
        $threshold = time() - $timeout; // Aktuell tid minus timeout

        // Hämta alla användare som varit online för länge
        $inactiveUsers = \App\Models\Status::where('active', '=', 'online')
            ->where('active_at', '<', $threshold)
            ->get();

        // Kolla om det finns några inaktiva användare att uppdatera
        if (empty($inactiveUsers)) {
            return;
        }

        // Markera varje inaktiv användare som offline
        foreach ($inactiveUsers as $userStatus) {
            // Uppdatera användarens status
            $userStatus->active = 'offline';
            $userStatus->active_at = time(); // Sätt en ny aktiv tidpunkt om det behövs
            $userStatus->save(); // Uppdatera raden i databasen
        }
    }
}
