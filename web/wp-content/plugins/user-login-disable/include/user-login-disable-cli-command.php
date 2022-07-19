<?php declare(strict_types=1);

class User_Login_Disable_CLI_Command
{
	private User_Login_Disable $userLoginDisable;

	public function __construct(User_Login_Disable $userLoginDisable)
	{
		$this->userLoginDisable = $userLoginDisable;
	}

	public function verify_user_ids(array $args): void
	{
		if (!is_array($args) || empty($args)) {
			WP_CLI::error("Must pass array of user ids!");
		}

		foreach ($args as $arg) {
			if (intval($arg) === 0 || $arg < 1) {
				WP_CLI::error('User ids must be positive integers!');
			}
		}
	}

	/**
	 * Enables users
	 *
	 * ## OPTIONS
	 *
	 * [<user_info>...]
	 * : A list of user ids, logins or emails for the users to be enabled
	 *
	 * [--all]
	 * : If this flag is included then all users in site will be enabled
	 *
	 */
	public function enable_users(array $user_args = [], array $assoc_args = []): void
	{
		$this->run_command($user_args, $assoc_args, true);
	}

	/**
	 * Disable users
	 *
	 * ## OPTIONS
	 *
	 * [<user_info>...]
	 * : A list of user ids, logins or emails for the users to be enabled
	 *
	 * [--all]
	 * : If this flag is included then all non-admin users in site will be disabled
	 *
	 */
	public function disable_users(array $user_args = [], array $assoc_args = []): void
	{
		$this->run_command($user_args, $assoc_args, false);
	}

	private function run_command(array $user_args, array $assoc_args, bool $enableUsers): void
	{
		if (empty($user_args) && empty($assoc_args)) {
			WP_CLI::error('Please specify one or more users, or use --all');
		}

		['all' => $allFlag] = $assoc_args;
		$user_ids = $this->run_user_query(
			$allFlag === true,
			!$enableUsers,
			$user_args,
		);

		$command = $enableUsers ? 'enable_user' : 'disable_user';

		$count = $this->userLoginDisable->enable_disable_users($command, $user_ids);

		WP_CLI::success("Disabled $count user(s)");
	}

	/**
	 * Builds user query for disabling or enabling users via WP_CLI
	 *
	 * @param bool $getAll
	 * @param bool $getEnabledUsers - If true we are getting users who are enabled, otherwise we're getting users who are disabled
	 * @param array $userArgs
	 */
    private function run_user_query(bool $getAll, bool $getEnabledUsers, array $userArgs = []): array
    {
        // Default to getting enabled users
        $meta = [
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'disabled',
                    'value' => '',
                ],
                [
                    'key' => 'disabled',
                    'compare' => 'NOT EXISTS',
                ]
            ],
        ];

        if (!$getEnabledUsers) {
            $meta = [
                'meta_key' => 'disabled',
                'meta_value' => 'disabled',
            ];
        }

		if ($getAll) {
			$query_args = [
				'fields' => 'ID',
				'role__not_in' => 'administrator',
				'meta_query' => $meta,
			];

			return get_users($query_args);
		}

		['user_ids' => $ids, 'user_logins_emails' => $logins_emails] = $this->split_ids_from_login_emails($userArgs);

        $query_args = [
            'fields' => ['ID', 'user_login', 'user_email'],
            'role__not_in' => 'administrator',
            'meta_query' => $meta,
        ];

        $user_info = get_users($query_args);

		return $this->filter_users_by_args($user_info, $ids, $logins_emails);
    }

	/**
	 * Filters list of user information by user login, id or email
	 *
	 * @param stdObject[] $user_info
	 * @param string[] $user_ids
	 * @param string[] $user_logins_emails
	 * @return string[] Array of matching user ids
	 */
	public function filter_users_by_args(array $user_info, array $user_ids, array $user_logins_emails): array
	{
		$matching_users = array_filter($user_info, function ($i) use ($user_ids, $user_logins_emails) {
			if (in_array($i->ID, $user_ids)) {
				return true;
			}

			if (in_array($i->user_login, $user_logins_emails)) {
				return true;
			}

			if (in_array($i->user_email, $user_logins_emails)) {
				return true;
			}

			return false;
		});

		return array_map(fn ($i) => $i->ID, $matching_users);
	}

	/**
	 * Takes a list of user emails, user logins and ids and returns
	 * an array with two values: one contains an array of the user ids passed in
	 * and another array with all the logins and emails
	 * @param  string[] $args - Array containing user ids, emails and login
	 * @return array
	 */
	public function split_ids_from_login_emails(array $args): array
	{
		$non_ids = [];
		$ids = array_filter($args, function($i) use (&$non_ids) {
			if (is_numeric($i)) {
				return true;
			}

			$non_ids[] = $i;

			return false;
		});

		return [
			'user_ids' => $ids,
			'user_logins_emails' => $non_ids,
		];
	}
}