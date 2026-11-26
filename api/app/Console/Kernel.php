<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\RevisarPlazos::class,
        Commands\ReevaluarProveedores::class,
        Commands\AplicarSeguridad::class,
        Commands\AplicarCuenta::class,
        Commands\ReconciliarEstados::class,
        Commands\AplicarMejoras::class,
        Commands\CrearUsuarioInterno::class,
        Commands\RecordarDocumentos::class,
        Commands\RevisarVencimientos::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * Para que corra se debe ejecutar "php artisan schedule:run" cada minuto
     * (cron en Linux o Programador de tareas en Windows).
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('plazos:revisar')->dailyAt('08:30');
        $schedule->command('proveedores:reevaluar')->monthlyOn(1, '07:00');
        $schedule->command('proveedores:recordar')->dailyAt('09:00');
        $schedule->command('documentos:vencimientos')->dailyAt('08:45');
    }
}
