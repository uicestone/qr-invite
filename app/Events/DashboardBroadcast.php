<?php namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class DashboardBroadcast extends Event implements ShouldBroadcast
{
    public $log;
    
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($log)
    {
        $this->log = $log;
    }

    /**
     * Get the channels the event should be broadcast on.
     *
     * @return array
     */
    public function broadcastOn()
    {
        return ['dashboard'];
    }
}
