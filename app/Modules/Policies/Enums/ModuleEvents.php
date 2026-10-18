<?php

namespace App\Modules\Policies\Enums;

/**
 * Standardized event names and module identifiers
 * Used for consistency when dispatching policy events
 */
enum ModuleEvents: string
{
    // ===================== LEAVE MODULE =====================
    case LEAVE_REQUEST_SUBMITTED = 'leave_request.submitted';
    case LEAVE_REQUEST_APPROVED = 'leave_request.approved';
    case LEAVE_REQUEST_REJECTED = 'leave_request.rejected';
    case LEAVE_REQUEST_CANCELLED = 'leave_request.cancelled';
    case LEAVE_REQUEST_WITHDRAWN = 'leave_request.withdrawn';

    // ===================== CLAIMS MODULE =====================
    case CLAIM_SUBMITTED = 'claim.submitted';
    case CLAIM_APPROVED = 'claim.approved';
    case CLAIM_REJECTED = 'claim.rejected';
    case CLAIM_PAID = 'claim.paid';

    // ===================== VEHICLE/FLEET MODULE =====================
    case VEHICLE_BOOKING_CREATED = 'vehicle.booking.created';
    case VEHICLE_BOOKING_CONFIRMED = 'vehicle.booking.confirmed';
    case VEHICLE_BOOKING_CANCELLED = 'vehicle.booking.cancelled';
    case VEHICLE_CHECKIN = 'vehicle.checkin';
    case VEHICLE_CHECKOUT = 'vehicle.checkout';

    // ===================== APPROVALS MODULE =====================
    case APPROVAL_REQUEST_CREATED = 'approval_request.created';
    case APPROVAL_REQUEST_APPROVED = 'approval_request.approved';
    case APPROVAL_REQUEST_REJECTED = 'approval_request.rejected';
    case APPROVAL_REQUEST_ESCALATED = 'approval_request.escalated';

    // ===================== PROCUREMENT MODULE =====================
    case PURCHASE_ORDER_CREATED = 'purchase_order.created';
    case PURCHASE_ORDER_APPROVED = 'purchase_order.approved';
    case PURCHASE_ORDER_REJECTED = 'purchase_order.rejected';
    case PURCHASE_ORDER_RECEIVED = 'purchase_order.received';

    // ===================== EXPENSE MODULE =====================
    case EXPENSE_SUBMITTED = 'expense.submitted';
    case EXPENSE_APPROVED = 'expense.approved';
    case EXPENSE_REJECTED = 'expense.rejected';

    // ===================== ATTENDANCE MODULE =====================
    case ATTENDANCE_CHECKED_IN = 'attendance.checked_in';
    case ATTENDANCE_CHECKED_OUT = 'attendance.checked_out';
    case ATTENDANCE_OVERTIME_RECORDED = 'attendance.overtime_recorded';

    // ===================== PAYROLL MODULE =====================
    case PAYROLL_RUN_CREATED = 'payroll_run.created';
    case PAYROLL_APPROVED = 'payroll.approved';
    case PAYROLL_RELEASED = 'payroll.released';

    public function getModule(): string
    {
        return match ($this) {
            // Leave events
            self::LEAVE_REQUEST_SUBMITTED,
            self::LEAVE_REQUEST_APPROVED,
            self::LEAVE_REQUEST_REJECTED,
            self::LEAVE_REQUEST_CANCELLED,
            self::LEAVE_REQUEST_WITHDRAWN => 'Leave',

            // Claims events
            self::CLAIM_SUBMITTED,
            self::CLAIM_APPROVED,
            self::CLAIM_REJECTED,
            self::CLAIM_PAID => 'Claims',

            // Fleet events
            self::VEHICLE_BOOKING_CREATED,
            self::VEHICLE_BOOKING_CONFIRMED,
            self::VEHICLE_BOOKING_CANCELLED,
            self::VEHICLE_CHECKIN,
            self::VEHICLE_CHECKOUT => 'Fleet',

            // Approvals events
            self::APPROVAL_REQUEST_CREATED,
            self::APPROVAL_REQUEST_APPROVED,
            self::APPROVAL_REQUEST_REJECTED,
            self::APPROVAL_REQUEST_ESCALATED => 'Approvals',

            // Procurement events
            self::PURCHASE_ORDER_CREATED,
            self::PURCHASE_ORDER_APPROVED,
            self::PURCHASE_ORDER_REJECTED,
            self::PURCHASE_ORDER_RECEIVED => 'Procurement',

            // Expense events
            self::EXPENSE_SUBMITTED,
            self::EXPENSE_APPROVED,
            self::EXPENSE_REJECTED => 'Expense',

            // Attendance events
            self::ATTENDANCE_CHECKED_IN,
            self::ATTENDANCE_CHECKED_OUT,
            self::ATTENDANCE_OVERTIME_RECORDED => 'Attendance',

            // Payroll events
            self::PAYROLL_RUN_CREATED,
            self::PAYROLL_APPROVED,
            self::PAYROLL_RELEASED => 'Payroll',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::LEAVE_REQUEST_SUBMITTED => 'User submitted a leave request',
            self::LEAVE_REQUEST_APPROVED => 'Leave request was approved',
            self::LEAVE_REQUEST_REJECTED => 'Leave request was rejected',
            self::LEAVE_REQUEST_CANCELLED => 'Leave request was cancelled',
            self::LEAVE_REQUEST_WITHDRAWN => 'Leave request was withdrawn by user',
            self::CLAIM_SUBMITTED => 'Expense claim was submitted',
            self::CLAIM_APPROVED => 'Expense claim was approved',
            self::CLAIM_REJECTED => 'Expense claim was rejected',
            self::CLAIM_PAID => 'Expense claim payment processed',
            self::VEHICLE_BOOKING_CREATED => 'Vehicle booking was created',
            self::VEHICLE_BOOKING_CONFIRMED => 'Vehicle booking was confirmed',
            self::VEHICLE_BOOKING_CANCELLED => 'Vehicle booking was cancelled',
            self::VEHICLE_CHECKIN => 'Vehicle checked in',
            self::VEHICLE_CHECKOUT => 'Vehicle checked out',
            self::APPROVAL_REQUEST_CREATED => 'Approval request created',
            self::APPROVAL_REQUEST_APPROVED => 'Approval request approved',
            self::APPROVAL_REQUEST_REJECTED => 'Approval request rejected',
            self::APPROVAL_REQUEST_ESCALATED => 'Approval request escalated',
            self::PURCHASE_ORDER_CREATED => 'Purchase order created',
            self::PURCHASE_ORDER_APPROVED => 'Purchase order approved',
            self::PURCHASE_ORDER_REJECTED => 'Purchase order rejected',
            self::PURCHASE_ORDER_RECEIVED => 'Purchase order items received',
            self::EXPENSE_SUBMITTED => 'Expense report submitted',
            self::EXPENSE_APPROVED => 'Expense report approved',
            self::EXPENSE_REJECTED => 'Expense report rejected',
            self::ATTENDANCE_CHECKED_IN => 'Employee checked in',
            self::ATTENDANCE_CHECKED_OUT => 'Employee checked out',
            self::ATTENDANCE_OVERTIME_RECORDED => 'Overtime recorded',
            self::PAYROLL_RUN_CREATED => 'Payroll run created',
            self::PAYROLL_APPROVED => 'Payroll approved for release',
            self::PAYROLL_RELEASED => 'Payroll released to employees',
        };
    }
}
