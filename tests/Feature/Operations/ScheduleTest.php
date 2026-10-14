<?php

namespace Tests\Feature\Operations;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_queue_worker_is_scheduled_only_without_a_dedicated_worker(): void
    {
        config(['techindex.queue.work_from_scheduler' => false]);
        $this->assertNull($this->queueWorker());

        // Hébergement Plesk : worker lancé chaque minute, un seul à la fois.
        config(['techindex.queue.work_from_scheduler' => true]);
        $worker = $this->queueWorker();

        $this->assertNotNull($worker);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertTrue($worker->withoutOverlapping);
        $this->assertTrue($worker->runInBackground);
        $this->assertStringContainsString('--stop-when-empty', (string) $worker->command);
    }

    public function test_update_tasks_follow_the_installation_mode(): void
    {
        config(['techindex.updates.deployment' => 'docker', 'techindex.updates.repository' => 'acme/techindex']);
        $commands = collect($this->loadSchedule()->events())->map(fn (Event $event): string => (string) $event->command);
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'techindex:update-check')));
        $this->assertFalse($commands->contains(fn (string $command): bool => str_contains($command, 'techindex:update-dispatch')));

        // Plesk : les mises à jour demandées depuis l'interface sont lancées chaque minute.
        config(['techindex.updates.deployment' => 'plesk']);
        $dispatch = collect($this->loadSchedule()->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'techindex:update-dispatch'));
        $this->assertNotNull($dispatch);
        $this->assertSame('* * * * *', $dispatch->expression);
    }

    public function test_scheduler_heartbeat_is_reported_by_the_diagnostic(): void
    {
        $heartbeat = collect($this->loadSchedule()->events())
            ->first(fn (Event $event): bool => $event->description === 'techindex:scheduler-heartbeat');

        $this->assertNotNull($heartbeat);
        $heartbeat->run($this->app);

        $this->assertIsInt(Cache::get('techindex:scheduler-heartbeat'));
        $this->artisan('techindex:doctor')->expectsOutputToContain('il y a 0 min');
    }

    private function queueWorker(): ?Event
    {
        return collect($this->loadSchedule()->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work'));
    }

    /**
     * Relit routes/console.php avec la configuration du test.
     */
    private function loadSchedule(): Schedule
    {
        $schedule = new Schedule;
        ScheduleFacade::swap($schedule);
        $this->app->instance(Schedule::class, $schedule);

        require base_path('routes/console.php');

        return $schedule;
    }
}
