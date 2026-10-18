<?php

namespace App\Core\ManagerUser\Service;

use App\Core\ManagerUser\Mail\UserWelcomeMail;
use App\Core\Module\Models\Modules;
use App\Core\User\Models\User;
use App\Core\UserManagement\Models\PasswordHistory;
use App\Core\UserManagement\Models\PasswordSecurity;
use App\Modules\HR\Hr\Models\HRPerson;
use Barryvdh\DomPDF\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use App\Core\Role\Models\Role;

class UserImportService
{
    protected $service;
    public function __construct(ManagerUserService $service)
    {
        $this->service = $service;
    }
    public function bulkImportUsers(Request $request): array
    {
        $validator = validator($request->all(), [
            'import_file' => 'required|file|mimes:xlsx,xls,csv|max:2048',
        ]);

        if ($validator->fails()) {
            return [
                'message' => 'Import failed',
                'errors'  => $validator->errors()->all(),
            ];
        }

        $file = $request->file('import_file');

        try {
            $importer = new class implements ToArray {
                public int   $imported = 0;
                public array $errors   = [];

                public function array(array $array): void
                {
                    foreach ($array as $index => $row) {
                        // Skip header rows (title, instructions, legend, section labels, column headers)
                        if ($index < 5) continue;

                        // ── Colonnes de base ──────────────────────────────────────────
                        $email          = trim((string) ($row[0]  ?? ''));
                        $role           = (int)   ($row[1]  ?? 1);
                        $statusRaw      = (int)   ($row[2]  ?? 1);
                        $firstName      = trim((string) ($row[3]  ?? ''));
                        $surname        = trim((string) ($row[4]  ?? ''));
                        $initial        = trim((string) ($row[5]  ?? ''));
                        $gender         = trim((string) ($row[6]  ?? ''));
                        $employeeNumber = trim((string) ($row[7]  ?? ''));
                        $position       = trim((string) ($row[8]  ?? ''));
                        $division       = trim((string) ($row[9]  ?? ''));
                        $department     = trim((string) ($row[10] ?? ''));
                        $occupLevel     = trim((string) ($row[11] ?? ''));
                        $jobFunction    = trim((string) ($row[12] ?? ''));
                        $disabled       = trim((string) ($row[13] ?? ''));
                        $disability     = trim((string) ($row[14] ?? ''));
                        $dateJoined     = trim((string) ($row[15] ?? ''));
                        $dateLeft       = trim((string) ($row[16] ?? ''));
                        $leaveProfile   = trim((string) ($row[17] ?? ''));
                        $modulesRaw     = trim((string) ($row[18] ?? ''));

                        $status = $statusRaw === 2 ? 0 : 1;

                        try {
                            // ── Validations ───────────────────────────────────────────
                            if (empty($email)) {
                                throw new \Exception("Missing email");
                            }

                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                throw new \Exception("Invalid email: {$email}");
                            }

                            if (User::where('email', $email)->exists()) {
                                throw new \Exception("Email already exists: {$email}");
                            }

                            $roleExists = Role::find($role);

                            // ── Création utilisateur ──────────────────────────────────
                            $plainPassword = Str::random(10) . '!';
                            $user = User::create([
                                'email'    => $email,
                                'password' => Hash::make($plainPassword),
                                'status'   => $status,
                            ]);

                            // ── Données personnelles / RH ─────────────────────────────
                            $personData = [
                                'first_name'       => $firstName,
                                'surname'          => $surname,
                                'initial'          => $initial ?: strtoupper(substr($firstName, 0, 1)),
                                'gender'           => $gender,
                                'email'            => $email,
                                'employee_number'  => $employeeNumber,
                                'position'         => $position ?: null,
                                'division'         => $division ?: null,
                                'department'       => $department ?: null,
                                'occupational_level' => $occupLevel ?: null,
                                'job_function'     => $jobFunction ?: null,
                                'disabled'         => filter_var($disabled, FILTER_VALIDATE_BOOLEAN),
                                'nature_of_disability' => $disability ?: null,
                                'date_joined'      => $dateJoined ?: null,
                                'date_left'        => $dateLeft ?: null,
                                'leave_profile'    => $leaveProfile ?: null,
                                'status'           => 1,
                            ];

                            $person = new HRPerson($personData);
                            $user->addPerson($person);

                            // ── Rôle ──────────────────────────────────────────────────
                            if ($roleExists) {
                                $user->syncRoles([$roleExists->name]);
                            }

                            // ── Mot de passe ──────────────────────────────────────────
                            PasswordHistory::createPassword($user->id, Hash::make($plainPassword));
                            PasswordSecurity::addExpiryDate($user->id);

                            // ── Accès modules ─────────────────────────────────────────

                            if (!empty($modulesRaw)) {
                                preg_match_all(
                                    "/'([a-zA-Z_]+)'\s*=>\s*(\d+)/",
                                    $modulesRaw,
                                    $matches,
                                    PREG_SET_ORDER
                                );

                                foreach ($matches as $match) {
                                    $moduleKey   = strtoupper(trim($match[1]));
                                    $accessLevel = (int) $match[2];

                                    $module = Modules::all()->first(function ($m) use ($moduleKey) {
                                        return strtoupper(trim($m->name)) === $moduleKey
                                            || strtoupper(trim($m->key))  === $moduleKey
                                            || strtoupper(trim($m->slug)) === $moduleKey;
                                    });

                                    if (!$module) {
                                        Log::warning("Module introuvable : {$moduleKey} (user: {$email})");
                                        continue;
                                    }

                                    ManagerUserService::giveUserAccess(
                                        $user->id,
                                        $module->category_id,
                                        $module->id,
                                        $accessLevel
                                    );
                                }
                            }
                            // ── Mail de bienvenue ─────────────────────────────────────
                            try {
                                Mail::to($email)->queue(new UserWelcomeMail(
                                    name: $firstName,
                                    email: $email,
                                    password: $plainPassword,
                                ));
                            } catch (\Exception $e) {
                                Log::warning("Failed to send email to {$email}: " . $e->getMessage());
                            }

                            $this->imported++;
                        } catch (\Exception $e) {
                            $this->errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                        }
                    }
                }
            };

            Excel::import($importer, $file);

            return [
                'message'  => 'success',
                'imported' => $importer->imported,
                'errors'   => $importer->errors,
            ];
        } catch (\Exception $e) {
            Log::error('User bulk import failed: ' . $e->getMessage());
            return [
                'message' => 'Import failed',
                'errors'  => [$e->getMessage()],
            ];
        }
    }
}
