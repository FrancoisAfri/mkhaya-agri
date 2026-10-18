<?php

namespace App\Core\ManagerUser\Service;

use App\Core\ManagerUser\Mail\UserWelcomeMail;
use App\Core\Module\Models\Modules;
use App\Core\User\Models\User;
use App\Core\UserManagement\Models\PasswordHistory;
use App\Core\UserManagement\Models\PasswordSecurity;
use App\Modules\HR\Hr\Models\HRPerson;
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
    public function bulkImportUsers(Request $request, bool $nonLogin = false): array
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
                public bool  $nonLogin = false;

                public function array(array $array): void
                {
                    foreach ($array as $index => $row) {
                        if ($index < 7) continue;

                        // ── Colonnes de base ──────────────────────────────────────────
                        $email          = trim((string) ($row[0]  ?? ''));
                        $role           = (int)   ($row[1]  ?? 1);
                        $statusRaw      = (int)   ($row[2]  ?? 1);
                        $firstName      = trim((string) ($row[3]  ?? ''));
                        $surname        = trim((string) ($row[4]  ?? ''));
                        $initial        = trim((string) ($row[5]  ?? ''));
                        $gender         = trim((string) ($row[6]  ?? ''));
                        $employeeNumber = trim((string) ($row[7]  ?? ''));
                        $title       = trim((string) ($row[8]  ?? ''));
                        // $division       = trim((string) ($row[9]  ?? ''));
                        // $department     = trim((string) ($row[10] ?? ''));
                        $occupLevel     = trim((string) ($row[9] ?? ''));
                        $jobFunction    = trim((string) ($row[10] ?? ''));
                        $disabled       = trim((string) ($row[11] ?? ''));
                        // $disability     = trim((string) ($row[14] ?? ''));
                        $dateJoined     = trim((string) ($row[12] ?? ''));
                        // $dateLeft       = trim((string) ($row[16] ?? ''));
                        // $leaveProfile   = trim((string) ($row[17] ?? ''));
                        $modulesRaw     = trim((string) ($row[13] ?? ''));

                        $status = $statusRaw === 2 ? 0 : 1;

                        try {
                            // ── Non-login employees: person-only, email optional ───────
                            if ($this->nonLogin) {
                                if (empty($firstName) || empty($surname)) {
                                    throw new \Exception("Missing first name or surname");
                                }
                                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                    throw new \Exception("Invalid email: {$email}");
                                }

                                HRPerson::create([
                                    'uuid'               => (string) Str::uuid(),
                                    'user_id'            => null,
                                    'first_name'         => $firstName,
                                    'surname'            => $surname,
                                    'initial'            => $initial ?: strtoupper(substr($firstName, 0, 1)),
                                    'gender'             => $gender,
                                    'email'              => $email ?: null,
                                    'employee_number'    => $employeeNumber ?: null,
                                    'title'              => $title ?: null,
                                    'occupational_level' => $occupLevel ?: null,
                                    'job_function'       => $jobFunction ?: null,
                                    'disabled'           => filter_var($disabled, FILTER_VALIDATE_BOOLEAN),
                                    'date_joined'        => strtotime($dateJoined) ?: null,
                                    'status'             => $status,
                                ]);

                                $this->imported++;
                                continue;
                            }

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

                            // ── ADD USER──────────────────────────────────
                            $plainPassword = Str::random(10) . '!';
                            $user = User::create([
                                'email'    => $email,
                                'password' => Hash::make($plainPassword),
                                'status'   => $status,
                            ]);


                            // ── PERSONAL DATA / RH ─────────────────────────────
                            $personData = [
                                'first_name'       => $firstName,
                                'surname'          => $surname,
                                'initial'          => $initial ?: strtoupper(substr($firstName, 0, 1)),
                                'gender'           => $gender,
                                'email'            => $email,
                                'uuid'=>str::uuid(),
                                'employee_number'  => $employeeNumber,
                                'title'            => $title ?: null,
                                'occupational_level' => $occupLevel ?: null,
                                'job_function'     => $jobFunction ?: null,
                                'disabled'         => filter_var($disabled, FILTER_VALIDATE_BOOLEAN),
                                'date_joined'      => strtotime($dateJoined) ?: null,
                                'status'           => 1,
                            ];

                            $person = new HRPerson($personData);
                            $user->addPerson($person);

                            // ── ROLE ──────────────────────────────────────────────────
                            if ($roleExists) {
                                $user->syncRoles([$roleExists->name]);
                            }

                            // ── PASSWORD ──────────────────────────────────────────
                            PasswordHistory::createPassword($user->id, Hash::make($plainPassword));
                            PasswordSecurity::addExpiryDate($user->id);

                            // ──MODULES ACCESS ─────────────────────────────────────────

                            if (!empty($modulesRaw)) {
                                $modulesRaw = preg_replace('/[\pZ\pC]/u', ' ', $modulesRaw);

                                $modulesRaw = str_replace(['‘', '’', '“', '”'], "'", $modulesRaw);

                                $modulesRaw = preg_replace('/\s*=>\s*/', '=>', $modulesRaw);

                                preg_match_all(
                                    "/'([a-zA-Z_\s]+)'=>(\d+)/",
                                    $modulesRaw,
                                    $matches,
                                    PREG_SET_ORDER
                                );

                                foreach ($matches as $match) {
                                    $moduleKey   = strtoupper(trim($match[1]));
                                    $accessLevel = (int) $match[2];

                                    //SEARCH MODULE
                                    $module = Modules::all()->first(function ($m) use ($moduleKey) {
                                        return strtoupper(trim($m->code_name)) === $moduleKey;
                                    });

                                    if (!$module) {
                                        Log::warning("Module Not found : {$moduleKey} (user: {$email})");
                                        continue;
                                    }

                                    //Give access
                                    ManagerUserService::giveUserAccess(
                                        $user->id,
                                        $module->category_id,
                                        $module->id,
                                        $accessLevel
                                    );
                                }
                            }
                            // ── SEND MAIL ─────────────────────────────────────
                            try {
                                Mail::to($email)->send(new UserWelcomeMail(
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

            $importer->nonLogin = $nonLogin;
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
