<?php

declare(strict_types=1);

return [
    'title' => 'Approvals',
    'subtitle' => 'Requests waiting on somebody to say yes.',
    'inbox' => 'Waiting on you',
    'empty' => 'Nothing is waiting on you.',
    'empty_hint' => 'Approvals you are asked for turn up here, and in your inbox.',
    'empty_all' => 'No approvals are open.',

    'filters' => [
        'mine' => 'Waiting on me',
        'all' => 'All open approvals',
    ],

    'status' => [
        'pending' => 'Waiting',
        'approved' => 'Approved',
        'rejected' => 'Not approved',
        'cancelled' => 'Withdrawn',
    ],

    'decision' => [
        'pending' => 'Not answered',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'skipped' => 'Not needed',
    ],

    'steps' => [
        'title' => 'Steps',
        'number' => 'Step :number',
        'state' => [
            'open' => 'Waiting',
            'waiting' => 'Not started',
            'approved' => 'Done',
            'rejected' => 'Refused',
            'cancelled' => 'Withdrawn',
        ],
    ],

    'mode' => [
        'any' => 'Anyone may decide',
        'all' => 'Everybody must agree',
        'any_short' => 'Any one',
        'all_short' => 'Everybody',
    ],

    'shape' => [
        'single' => 'Single approver',
        'parallel' => 'Parallel',
        'sequential' => 'Sequential',
        'empty' => 'No steps yet',
    ],

    'approver_type' => [
        'users' => 'Named people',
        'team' => 'Everybody in a team',
        'role' => 'Everybody with a role',
        'manager' => 'The requester’s manager',
        'field' => 'Whoever the form names',
    ],

    'actions' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
        'cancel' => 'Withdraw',
        'request' => 'Ask for approval',
        'decide' => 'Answer this',
    ],

    'fields' => [
        'subject' => 'What is being approved',
        'reason' => 'Why',
        'comment' => 'Your comment',
        'comment_help' => 'Optional when approving. Worth writing when refusing — the requester reads it.',
        'workflow' => 'Approval workflow',
        'workflow_none' => 'Ask named people instead',
        'approvers' => 'Who has to answer',
        'due_hours' => 'Answer within (hours)',
        'due_hours_help' => 'Reported as overdue after this. Nothing is decided automatically.',
        'due_within' => 'within :hours h',
        'approver_field' => 'Form field holding the approver',
        'approver_field_help' => 'The key of a user or e-mail field on the request form.',
        'step_name' => 'Step name',
        'requires_approval' => 'Needs approval first',
        'requires_approval_help' => 'This transition is refused until an approval on the ticket has been granted — including when no approval has been raised at all.',
        'manager' => 'Manager',
        'manager_help' => 'Used by approval steps that ask “the requester’s manager”.',
        'manager_none' => 'Nobody',
    ],

    'ticket' => [
        'title' => 'Approval',
        'none' => 'No approval on this ticket.',
        'blocked' => 'This ticket is waiting on an approval.',
        'rejected' => 'This request was not approved.',
        'requested_by' => 'Asked by :name',
        'asked' => 'Asked :time',
        'answered' => 'Answered :time',
        'overdue' => 'Overdue',
        'due' => 'Due :time',
        'waiting_on' => 'Waiting on :names',
        'source_email' => 'answered by e-mail',
    ],

    'portal' => [
        'title' => 'Approval',
        'pending' => 'Your request is waiting on an approval.',
        'approved' => 'Your request has been approved.',
        'rejected' => 'Your request was not approved.',
    ],

    'token' => [
        'title' => 'Approve this request',
        'intro' => 'You were asked to approve the following.',
        'expired_title' => 'This link no longer works',
        'expired' => 'It may have been used already, withdrawn, or simply expired. Sign in to see what is still waiting on you.',
        'done_approved' => 'Thank you — recorded as approved.',
        'done_rejected' => 'Thank you — recorded as not approved.',
        'sign_in' => 'Sign in to Ticktz',
    ],

    'flash' => [
        'requested' => 'Approval requested.',
        'approved' => 'Recorded as approved.',
        'rejected' => 'Recorded as not approved.',
        'cancelled' => 'Approval withdrawn.',
    ],

    'errors' => [
        'already_decided' => 'That approval has already been answered.',
        'unknown_outcome' => 'An approval is either granted or refused.',
        'no_approvers' => 'That approval workflow resolved to nobody, so nothing was raised.',
        'nobody_named' => 'Choose an approval workflow or name at least one approver.',
    ],

    'log' => [
        'requested' => 'Approval requested: :subject',
        'approved' => ':name approved :subject',
        'rejected' => ':name refused :subject',
        'settled_approved' => 'Approved: :subject',
        'settled_rejected' => 'Not approved: :subject',
        'cancelled' => 'Approval withdrawn: :subject',
        'no_approvers' => 'Approval resolved to nobody: :subject',
        'step_skipped' => 'Approval step ":step" had no approvers and was skipped',
    ],

    'admin' => [
        'title' => 'Approval workflows',
        'subtitle' => 'Who has to say yes, and in what order.',
        'add' => 'New workflow',
        'edit' => 'Edit workflow',
        'empty' => 'No approval workflows yet.',
        'empty_hint' => 'A workflow is an ordered list of steps. One step with one person is a single approval; several steps run one after another.',
        'created' => 'Workflow :name created.',
        'updated' => 'Workflow :name updated.',
        'deleted' => 'Workflow removed. Approvals already running are unaffected.',
        'confirm_delete' => 'Remove this workflow? Request types using it stop requiring approval; approvals already running carry on.',
        'add_step' => 'Add a step',
        'remove_step' => 'Remove step',
        'used_by' => ':count request type|:count request types',
        'instructions' => 'What to tell the approver',
        'instructions_help' => 'Shown in the notification and on the decision page.',
        'steps_help' => 'Steps run in order. Within a step, approvers are asked at the same time.',
    ],
];
