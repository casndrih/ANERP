<?php
namespace App\Console\Commands;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class InstallAnERP extends Command {
    protected $signature = 'anerp:install-admin';
    protected $description = 'Create the first ANERP company and system administrator.';
    public function handle(): int {
        if (User::exists() || Company::exists()) {
            $this->error('ANERP already contains users or companies. Use the secured administration interface instead.');
            return self::FAILURE;
        }
        $companyName = $this->ask('Legal or trading company name');
        $adminName = $this->ask('Administrator full name');
        $email = $this->ask('Administrator email');
        $password = $this->secret('Administrator password (minimum 12 characters)');
        if (!$companyName || !$adminName || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen((string)$password)<12) {
            $this->error('Valid names/email and a password of at least 12 characters are required.');
            return self::FAILURE;
        }
        DB::transaction(function () use ($companyName,$adminName,$email,$password) {
            $company = Company::create(['name'=>$companyName,'legal_name'=>$companyName,'currency'=>'PGK','country'=>'PG','timezone'=>'Pacific/Port_Moresby']);
            $user = User::create(['name'=>$adminName,'email'=>$email,'password'=>$password]);
            $user->companies()->attach($company->id,['role_code'=>'owner','is_active'=>true]);
        });
        $this->info('Initial company and administrator created. Sign in through the ANERP web interface.');
        return self::SUCCESS;
    }
}
