<?php

declare(strict_types=1);

namespace App\Services;

/** Station registrations, stations and drivers in Supabase. Row-level security decides what each token sees. */
final class Registry
{
    public const VEHICLES = ['Motorcycle with sidecar', 'Tricycle', 'Delivery van'];
    public const STATION_COLUMNS = 'id,slug,name,barangay,address,phone,email,registration_status,active,weekday_open,weekday_close,sunday_open,sunday_close,closed_notice,created_at';

    public function __construct(private readonly Gateway $db)
    {
    }

    /** Visitor submission; returns the new registration id. */
    public function submit(array $form): string
    {
        $result = $this->db->rpc('submit_station_registration', [
            'p_station_name' => $form['station_name'],
            'p_barangay' => $form['barangay'],
            'p_address' => $form['address'],
            'p_station_phone' => $form['station_phone'],
            'p_station_email' => $form['station_email'],
            'p_owner_name' => $form['owner_name'],
            'p_owner_email' => $form['owner_email'],
            'p_owner_phone' => $form['owner_phone'],
            'p_owner_role' => $form['owner_role'],
        ], null);

        return (string) ($result['value'] ?? '');
    }

    public function review(string $token, string $id, string $decision, ?string $reason): array
    {
        return $this->db->rpc('review_station_registration', ['p_id' => $id, 'p_decision' => $decision, 'p_reason' => $reason], $token);
    }

    public function registrations(string $token): array
    {
        return $this->db->select('station_registrations', ['select' => '*', 'order' => 'created_at.desc'], $token);
    }

    public function stations(string $token): array
    {
        return $this->db->select('stations', ['select' => self::STATION_COLUMNS, 'order' => 'name.asc'], $token);
    }

    public function drivers(string $token, ?string $stationId = null): array
    {
        $query = ['select' => 'user_id,station_id,full_name,phone,email,vehicle,plate_number,capacity,active,created_at', 'order' => 'created_at.desc'];
        if ($stationId !== null) {
            $query['station_id'] = 'eq.' . $stationId;
        }

        return $this->db->select('drivers', $query, $token);
    }

    public function provisionMember(string $userId, string $stationId, string $role): void
    {
        $this->db->adminRpc('provision_station_member', ['p_user_id' => $userId, 'p_station_id' => $stationId, 'p_role' => $role]);
    }

    public function provisionPlatformAdmin(string $userId): void
    {
        $this->db->adminRpc('provision_platform_admin', ['p_user_id' => $userId]);
    }

    public function registerDriver(string $userId, string $stationId, array $driver, string $createdBy): void
    {
        $this->db->adminRpc('register_driver', [
            'p_user_id' => $userId, 'p_station_id' => $stationId, 'p_full_name' => $driver['full_name'],
            'p_phone' => $driver['phone'], 'p_email' => $driver['email'], 'p_vehicle' => $driver['vehicle'],
            'p_plate' => $driver['plate'], 'p_capacity' => $driver['capacity'], 'p_created_by' => $createdBy,
        ]);
    }
}
