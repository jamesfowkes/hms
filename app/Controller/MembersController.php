<?php
	
	App::uses('HmsAuthenticate', 'Controller/Component/Auth');
	App::uses('Member', 'Model');
	App::uses('CakeEmail', 'Network/Email');

	App::uses('PhpReader', 'Configure');
	Configure::config('default', new PhpReader());
	Configure::load('hms', 'default');

	/**
	 * Controller for Member functions.
	 *
	 *
	 * @package       app.Controller
	 */
	class MembersController extends AppController 
	{
	    
	    //! We need the Html, Form, Tinymce and Currency helpers.
	    /*!
	    	@sa http://api20.cakephp.org/class/html-helper
	    	@sa http://api20.cakephp.org/class/form-helper
	    	@sa http://api20.cakephp.org/class/paginator-helper
	    	@sa TinymceHelper
	    	@sa CurrencyHelper
	    */
	    public $helpers = array('Html', 'Form', 'Paginator', 'Tinymce', 'Currency');

	    //! We need the MailChimp and Krb components.
	    /*!
	    	@sa MailChimpComponent
	    	@sa KrbComponent
	    */
	    public $components = array('MailChimp');

	    //! Test to see if a user is authorized to make a request.
	    /*!
	    	@param array $user Member record for the user.
	    	@param CakeRequest $request The request the user is attempting to make.
	    	@retval bool True if the user is authorized to make the request, otherwise false.
	    	@sa http://api20.cakephp.org/class/cake-request
	    */
	    public function isAuthorized($user, $request)
	    {
	    	if(parent::isAuthorized($user, $request))
	    	{
	    		return true;
	    	}

	    	$userIsMemberAdmin = $this->Member->GroupsMember->isMemberInGroup( Hash::extract($user, 'Member.member_id'), Group::MEMBER_ADMIN );
	    	$actionHasParams = isset( $request->params ) && isset($request->params['pass']) && count( $request->params['pass'] ) > 0;
	    	$userIdIsSet = isset( $user['Member'] ) && isset( $user['Member']['member_id'] );
	    	$userId = $userIdIsSet ? $user['Member']['member_id'] : null;

	    	switch ($request->action) 
	    	{
	    		case 'index':
	    		case 'listMembers':
	    		case 'listMembersWithStatus':
	    		case 'emailMembersWithStatus':
	    		case 'search':
	    		case 'setMemberStatus':
	    		case 'acceptDetails':
	    		case 'rejectDetails':
	    		case 'approveMember':
	    		case 'sendMembershipReminder':
	    		case 'sendContactDetailsReminder':
	    		case 'sendSoDetailsReminder':
	    		case 'addExistingMember':
	    			return $userIsMemberAdmin; 

	    		case 'changePassword':
	    		case 'view':
	    		case 'edit':
	    		case 'setupDetails':
	    			if( $userIsMemberAdmin || 
	    				( $actionHasParams && $userIdIsSet && $request->params['pass'][0] == $userId ) )
	    			{
	    				return true;
	    			}
	    			break;

	    		case 'login':
	    		case 'logout':
	    		case 'setupLogin':
	    		case 'register':
	    			return true;
	    	}

	    	return false;
	    }

	    //! Email object, for easy mocking.
	    public $email = null;

	    //! Perform any actions that should be performed before any controller action
	    /*!
	    	@sa http://api20.cakephp.org/class/controller#method-ControllerbeforeFilter
	    */
	    public function beforeFilter() 
	    {
	        parent::beforeFilter();
	        $this->Auth->allow('logout', 'login', 'register', 'forgot_password', 'setupLogin', 'setup_details');
	    }

	    //! Show a list of all Status and a count of how many members are in each status.
	    public function index() 
	    {
	    	$this->set('memberStatusInfo', $this->Member->Status->getStatusSummaryAll());
	    	$this->set('memberTotalCount', $this->Member->getCount());

	    	$this->Nav->add('Register Member', 'members', 'register');
    		$this->Nav->add('E-mail all current members', 'members', 'email_members_with_status', array( Status::CURRENT_MEMBER ) );
	    }

		//! Show a list of all members, their e-mail address, status and the groups they're in.
		public function listMembers() 
		{

			/*
	    	    Actions should be added to the array like so:
	    	    	[actions] =>
	    					[n]
	    						[title] => action title
	    						[controller] => action controller
	    						[action] => action name
	    						[params] => array of params
	    	*/
	        $this->_paginateMemberList($this->Member->getMemberSummaryAll(true));
	    }

		//! List all members with a particular status.
		/*!
			@param int $statusId The status to list all members for.
		*/
		public function listMembersWithStatus($statusId) 
		{
			// Use the list members view
			$this->view = 'list_members';

			// If statusId is not set, list all the members
			if(!isset($statusId))
			{
				return $this->redirect( array('controller' => 'members', 'action' => 'listMembers') );
			}

			$this->_paginateMemberList($this->Member->getMemberSummaryForStatus(true, $statusId));
	        $this->set('statusInfo', $this->Member->Status->getStatusSummaryForId($statusId));
	    }

	    //! List all members who's name, email, username or handle is similar to the search term.
		public function search() 
		{
			// Use the list members view
			$this->view = 'list_members';

			// If search term is not set, list all the members
			if(	!isset($this->params['url']['query']))
			{
				return $this->redirect( array('controller' => 'members', 'action' => 'listMembers') );
			}

			$keyword = $this->params['url']['query'];

	        $this->_paginateMemberList($this->Member->getMemberSummaryForSearchQuery(true, $keyword));
	    }

	    //! Perform all the actions needed to get a paginated member list with actions applied.
	    /*!
	    	@param array $queryResult The query to pass to paginate(), usually obtained from a Member::getMemberSummary**** method.
	    */
	    private function _paginateMemberList($queryResult)
	    {
	    	$this->paginate = $queryResult;
	        $memberList = $this->paginate('Member');
	        $memberList = $this->Member->formatMemberInfo($memberList);
	    	$memberList = $this->_addMemberActions($memberList);
	        $this->set('memberList', $memberList);
	    }

	    //! Grab a users e-mail address and start the membership procedure.
	    public function register() 
	    {
	    	// Need a list of mailing-lists that the user can opt-in to
	    	$mailingLists = $this->_get_mailing_lists_and_subscruibed_status(null);
			$this->set('mailingLists', $mailingLists);

			if($this->request->is('post'))
			{
				$result = $this->Member->registerMember( $this->request->data );

				if( $result )
				{
					$status = $result['status'];

					if( $status != Status::PROSPECTIVE_MEMBER )
					{
						// User is already way past this membership stage, send them to the login page
						$this->Session->setFlash( 'User with that e-mail already exists.' );
						return $this->redirect( array('controller' => 'members', 'action' => 'login') );
					}

					$email = $result['email'];

					// E-mail the member admins for a created record
					if( $result['createdRecord'] === true )
					{
						$this->_sendEmail(
	                		$this->Member->getEmailsForMembersInGroup(Group::MEMBER_ADMIN),
	                		'New Prospective Member Notification',
	                		'notify_admins_member_added',
	                		array( 
								'email' => $email,
							)
	                	);
					}

					$memberId = $result['memberId'];

					// But e-mail the member either-way
					$this->_sendProspectiveMemberEmail($memberId);

					$this->Session->setFlash( 'Registration successful, please check your inbox.' );
					return $this->redirect(array( 'controller' => 'pages', 'action' => 'home'));
				}
				else
				{
					$this->Session->setFlash( 'Unable to register.' );
				}
			}
		}

	    //! Allow a member to set-up their initial login details.
	    /*
	    	@param int $id The id of the member whose details we want to set-up.
	    */
	    public function setupLogin($id = null)
	    {
	    	if( $id == null )
	    	{
	    		return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    	}

	    	if($this->request->is('post'))
	    	{
	    		try
	    		{
	    			if( $this->Member->setupLogin($id, $this->request->data) )
		    		{
		    			$this->Session->setFlash('Username and Password set, please login.');
		    			return $this->redirect(array( 'controller' => 'members', 'action' => 'login'));
		    		}
		    		else
		    		{
		    			$this->Session->setFlash('Unable to set username and password.');
		    		}
	    		}
	    		catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));		
	    		}
	    	}
	    }

	    //! Allow a member who is logged in to set-up their contact details.
	    /*
	    	@param int $id The id of the member whose contact details we want to set-up.
	    */
	    public function setupDetails($id = null)
	    {
	    	// Can't do this if id isn't the same as that of the logged in user.
	    	if( $id == null ||
	    		$id == AuthComponent::user('Member.member_id') )
	    	{
	    		return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    	}

	    	if( $this->request->is('post') )
	    	{
	    		try
	    		{
	    			if( $this->Member->setupDetails($id, $this->request->data) )
		    		{
		    			$memberEmail = $this->Member->getEmailForMember($id);

		    			$this->Session->setFlash('Contact details saved.');

						$this->_sendEmail(
							$this->Member->getEmailsForMembersInGroup(Group::MEMBER_ADMIN),
							'New Member Contact Details',
							'notify_admins_check_contact_details',
							array( 
								'email' => $memberEmail,
								'id' => $id,
							)
						);

						$this->_sendEmail(
							$memberEmail,
							'Contact Information Completed',
							'to_member_post_contact_update'
						);

						return $this->redirect(array( 'controller' => 'members', 'action' => 'view', $id));
		    		}
		    		else
		    		{
		    			$this->Session->setFlash('Unable to save contact details.');
		    		}
	    		}
	    		catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
	    	}
	    }

	    //! Reject the contact details a member has supplied, with a message to say why.
	    /*!
	    	@param int $id The id of the member whose contact details we're rejecting.
	    */
	    public function rejectDetails($id = null) 
	    {
	    	if( $id == null )
	    	{
	    		return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    	}

	    	if($this->request->is('post'))
	    	{
	    		try
	    		{
		    		if($this->Member->rejectDetails($id, $this->request->data, $this->_getLoggedInMemberId()))
		    		{
		    			$this->Session->setFlash('Member has been contacted.');

		    			$memberEmail = $this->Member->getEmailForMember($id);

		    			Controller::loadModel('MemberEmail');
		    			
		    			$this->_sendEmail(
		    				$memberEmail,
		    				'Issue With Contact Information',
		    				'to_member_contact_details_rejected',
		    				array(
		    					'message' => $this->MemberEmail->getMessage( $this->request->data )
		    				)
		    			);

		    			return $this->redirect(array( 'controller' => 'members', 'action' => 'view', $id));
		    		}
		    		else
		    		{
		    			$this->Session->setFlash('Unable to set member status. Failed to reject details.');
		    		}
	    		}
	    		catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
	    	}	    	
	    }

	    //! Accept the contact details a member has supplied.
	    /*!
	    	@param int $id The id of the member whose contact details we're accepting.
	    */
	    public function acceptDetails($id = null)
	    {
	    	if( $id == null )
	    	{
	    		return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    	}

	    	$this->set('accounts', $this->Member->getReadableAccountList());

	    	if($this->request->is('post'))
	    	{
	    		try
	    		{
	    			$memberDetails = $this->Member->acceptDetails($id, $this->request->data, $this->_getLoggedInMemberId());
		    		if($memberDetails)
		    		{
		    			$this->Session->setFlash('Member details accepted.');

		    			$this->_sendSoDetailsToMember($id);

						$this->_sendEmail(
							$this->Member->getEmailsForMembersInGroup(Group::MEMBER_ADMIN),
							'Impending Payment',
							'notify_admins_payment_incoming',
							$memberDetails
						);

						return $this->redirect(array( 'controller' => 'members', 'action' => 'view', $id));
		    		}
		    	}
		    	catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
	    	}
	    }

	    //! Approve a membership
	    /*!
	    	@param int $id The id of the member who we are approving.
	    */
	    public function approveMember($id = null) 
	    {
	    	try
	    	{
	    		$memberDetails = $this->Member->approveMember($id, $this->_getLoggedInMemberId());
	    		if($memberDetails)
		    	{
		    		$this->Session->setFlash('Member has been approved.');

		    		$adminDetails = $this->Member->getMemberSummaryForMember($this->_getLoggedInMemberId());

		    		// We only notify the admin that approved them.
		    		$this->_sendEmail(
		    			$adminDetails['email'],
		    			'Member Approved',
		    			'notify_admins_member_approved',
		    			$memberDetails
		    		);

		    		// E-mail the member
		    		$this->_sendEmail(
		    			$memberDetails['email'],
		    			'Membership Complete',
		    			'to_member_access_details',
		    			array( 
							'adminName' => $adminDetails['name'],
							'adminEmail' => $adminDetails['email'],
							'manLink' => Configure::read('hms_help_manual_url'),
							'outerDoorCode' => Configure::read('hms_access_street_door'),
							'innerDoorCode' => Configure::read('hms_access_inner_door'),
							'wifiSsid' => Configure::read('hms_access_wifi_ssid'),
							'wifiPass' => Configure::read('hms_access_wifi_password'),
						)
		    		);
		    	}
		    	else
		    	{
		    		$this->Session->setFlash('Member details could not be updated.');
		    	}
	    	}
	    	catch(InvalidStatusException $e)
    		{
    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
    		}
	    	
	    	return $this->redirect($this->referer());
	    }

	    //! Change a members password
	    /*!
	    	@param int $id The id of the member whose password we are changing.
	    */
	    public function changePassword($id) 
	    {
	    	$memberInfo = $this->Member->getMemberSummaryForMember($id);
	    	if(!$memberInfo)
	    	{
	    		return $this->redirect($this->referer());
	    	}

	    	$adminId = $this->_getLoggedInMemberId();
	    	$this->set('id', $id);
	    	$this->set('name', $memberInfo['name']);
	    	$this->set('ownAccount', $adminId == $id);

	    	if($this->request->is('post'))
	    	{
		    	try
		    	{
		    		if($this->Member->changePassword($id, $adminId, $this->request->data))
		    		{
		    			$this->Session->setFlash('Password updated.');
		    			return $this->redirect(array( 'controller' => 'members', 'action' => 'view', $id));
		    		}
		    		else
		    		{
		    			$this->Session->setFlash('Unable to update password.');
		    		}
		    	}
		    	catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
	    		catch(NotAuthorizedException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
	    	}
	    }

	    //! Generate or complete a forgot password request.
	    /*
	    	@param string $guid The id of the request, may be null.
	    */
	    public function forgotPassword($guid = null)
	    {
	    	if($guid != null)
	    	{
	    		if(!ForgotPassword::isValidGuid($guid))
	    		{
	    			$guid = null;
	    		}
	    	}

	    	$this->set('createRequest', $guid == null);

	    	if($this->request->is('post'))
    		{
    			try
		    	{
		    		if($guid == null)
			    	{
			    		$data = $this->Member->createForgotPassword($this->request->data);
			    		if($data != false)
			    		{
			    			$this->_sendEmail(
			    				$data['email'],
			    				'Password Reset Request',
			    				'forgot_password',
			    				array(
			    					'id' => $data['id'],
			    				)
			    			);

			    			return $this->redirect(array('controller' => 'pages', 'action' => 'forgot_password_sent'));
			    		}
			    		else
			    		{
			    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
			    		}
			    	}
			    	else
			    	{
			    		if($this->Member->completeForgotPassword($guid, $this->request->data))
			    		{
			    			$this->Session->setFlash('Password successfully set.');
							return $this->redirect(array('controller' => 'members', 'action' => 'login'));
			    		}
			    		else
			    		{
			    			$this->Session->setFlash('Unable to set password');
			    			return $this->redirect(array('controller' => 'pages', 'action' => 'forgot_password_error'));
			    		}
			    	}
			    }
			    catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
    		}
	    }

	    //! Send the 'prospective member' email to a member.
	    /*!
	    	@param int $id The id of the member to send the message to.
	    */
	    public function sendMembershipReminder($id = null)
	    {
	    	if($id != null)
	    	{
	    		if($this->_sendProspectiveMemberEmail($id))
    			{
					$this->Session->setFlash('Member has been contacted');
    			}
    			else
    			{
    				$this->Session->setFlash('Unable to contact member');
    			}
	    	}

	    	return $this->redirect($this->referer());
	    }

	    //! Send the 'prospective member' email to a member.
	    /*!
	    	@param int $memberId The id of the member to send the message to.
	    	@retval bool True if e-mail was sent.
	    */
	    private function _sendProspectiveMemberEmail($memberId)
	    {
	    	$email = $this->Member->getEmailForMember($memberId);
	    	if($email)
	    	{
	    		return $this->_sendEmail(
					$email,
					'Welcome to Nottingham Hackspace',
					'to_prospective_member',
					array(
						'memberId' => $memberId,
					)
				);
	    	}
	    	return false;
	    }

	    //! Send the 'contact details reminder' email to a member.
	    /*!
	    	@param int $id The id of the member to contact.
	    */
	    public function sendContactDetailsReminder($id = null)
	    {
	    	$emailSent = false;

	    	$email = $this->Member->getEmailForMember($id);
	    	if($email)
	    	{
	    		$emailSent = $this->_sendEmail(
					$email,
					'Membership Info',
					'to_member_contact_details_reminder',
					array(
						'memberId' => $id,
					)
				);
	    	}

	    	if($emailSent)
			{
				$this->Session->setFlash('Member has been contacted');
			}
			else
			{
				$this->Session->setFlash('Unable to contact member');
			}

			return $this->redirect($this->referer());
	    }

	    //! Send the 'so details reminder' email to a member.
	    /*!
	    	@param int $id The id of the member to contact.
	    */
	    public function sendSoDetailsReminder($id = null)
	    {
	    	if($this->_sendSoDetailsToMember($id))
	    	{
	    		$this->Session->setFlash('Member has been contacted');
	    	}
	    	else
	    	{
	    		$this->Session->setFlash('Unable to contact member');
	    	}
	    	return $this->redirect($this->referer());
	    }

	    //! Send the e-mail containing standing order info to a member.
	    /*
	    	@param int $memberId The id of the member to send the reminder to.
	    	@return bool True if mail was sent, false otherwise.
	    */
	    private function _sendSoDetailsToMember($memberId)
	    {
	    	$memberSoDetails = $this->Member->getSoDetails($memberId);
	    	if($memberSoDetails != null)
	    	{
	    		return $this->_sendEmail(
	    			$memberSoDetails['email'],
	    			'Bank Details',
	    			'to_member_so_details',
	    			array( 
						'name' => $memberSoDetails['name'],
						'paymentRef' => $memberSoDetails['paymentRef'],
						'accountNum' => Configure::read('hms_so_accountNumber'),
						'sortCode' => Configure::read('hms_so_sortCode'),
						'accountName' => Configure::read('hms_so_accountName'),
					)
	    		);
	    	}

			return false;
	    }

	    public function view($id = null) 
	    {
	        $this->Member->id = $id;
	        $memberInfo = $this->Member->read();

	        # Sanitise data
		    $user = $this->Member->findByMemberId(AuthComponent::user('Member.member_id'));
		    $canSeeAll = Member::isInGroupMemberAdmin($user) || Member::isInGroupFullAccess($user);
		    if(!$canSeeAll)
		    {
		    	unset($memberInfo['Pin']);
		    	unset($memberInfo['Status']);
		    	unset($memberInfo['StatusUpdate']);

		    	// Only current members can see credit limit and balances
		    	if($user['Member']['member_status'] != 2)
		    	{
		    		unset($memberInfo['Member']['balance']);
		    		unset($memberInfo['Member']['credit_limit']);
		    	}
		    }
		    else
		    {
		    	if(empty($memberInfo['StatusUpdate']) == false)
		    	{
		    		$memberInfo['StatusUpdate'] = $this->Member->StatusUpdate->findById($memberInfo['StatusUpdate'][0]['id']);
		    	}
		    }

	        $this->set('member', $memberInfo);

	        $this->Nav->add('Edit', 'members', 'edit', array( $id ) );
	        $this->Nav->add('Change Password', 'members', 'change_password', array( $id ) );
			switch ($memberInfo['Member']['member_status']) 
			{
		        case 1: # Prospective member
		        	$this->Nav->add('Send Membership Reminder', 'members', 'send_membership_reminder', array($id));
		        	break;

		        case 2: # Current member
		            $this->Nav->add('Revoke Membership', 'members', 'set_member_status', array( $id, 3 ) );
		            break;

		        case 3: # Ex-member
		            $this->Nav->add('Reinstate Membership', 'members', 'set_member_status', array( $id, 2 ) );
		            break;

				case 5: # Waiting for contact details
					$this->Nav->add('Send Contact Details Reminder', 'members', 'send_contact_details_reminder', array($id));
		        	break;

		        case 6: # Prospective member
		            $this->Nav->add('Approve contact details', 'members', 'accept_details', array( $id ), 'positive' );
		            $this->Nav->add('Reject contact details', 'members', 'reject_details', array( $id ), 'negative' );
		            break;

		        case 7: # Waiting for SO
		        	$this->Nav->add('Send SO Details Reminder', 'members', 'send_so_details_reminder', array($id));
		        	$this->Nav->add('Approve Member', 'members', 'approve_member', array($id), 'positive');
		        	break;
		    }

		    $this->set('mailingLists', $this->_get_mailing_lists_and_subscruibed_status($memberInfo));
	    }

	    public function edit($id = null) 
	    {

	    	$this->set('groups', $this->Member->Group->find('list',array('fields'=>array('grp_id','grp_description'))));
	    	$this->set('statuses', $this->Member->Status->find('list',array('fields'=>array('status_id','title'))));
	    	# Add a value for using the existing account
	    	$accountsList =	$this->get_readable_account_list( array( -1 => 'Use Default' ) );

	    	$this->set('accounts', $accountsList);
			$this->Member->id = $id;
			$data = $this->Member->read(null, $id);

			# Can't be on this screen unless we've entered all the member details
			if($data['Member']['member_status'] == 5)
			{
				$this->redirect(array('action' => 'setup_details', $data['Member']['member_id']));
			}
			else
			{

				$mailingLists = $this->_get_mailing_lists_and_subscruibed_status($data);
				$this->set('mailingLists', $mailingLists);

				if ($this->request->is('get')) 
				{
				    $this->request->data = $this->sanitise_edit_data($data);
				} 
				else 
				{
					# Need to set some more info about the pin
					$this->request->data['Pin']['pin_id'] = $data['Pin']['pin_id'];

					# Clear the actual pin number though, so that won't get updated
					unset($this->request->data['Pin']['pin']);

					$this->request->data = $this->sanitise_edit_data($this->request->data);


				    if ($this->Member->saveAll($this->request->data)) 
				    {

				    	$flashMessage = 'Member details updated.';

				    	if(isset($this->request->data['MailingLists']))
				    	{
				    		if(!isset($this->request->data['MailingLists']['MailingLists']) ||
				    			!is_array($this->request->data['MailingLists']['MailingLists']))
				    		{
				    			$this->request->data['MailingLists']['MailingLists'] = array();
				    		}

				    		$flashMessage .= '<br>';
				    		$flashMessage .= $this->_update_mailing_list_subscriptions($id, $this->request->data['MailingLists']['MailingLists']);
				    	}


				    	$memberInfo = $this->set_account($this->request->data);
				    	$this->set_member_status_impl($data, $memberInfo);
						$this->update_status_on_joint_accounts($data, $memberInfo);

				        $this->Session->setFlash($flashMessage);
				        $this->redirect(array('action' => 'view', $id));
				    } 
				    else 
				    {
				        $this->Session->setFlash('Unable to update member details.');
				    }
				}
			}
		}

		private function _update_mailing_list_subscriptions($memberId, $subscribeToLists)
		{
			$resultMessage = '';

			# Grab a list of all the mailing lists we know about
			# including whether this member is subscribed to them or not
			$memberInfo = $this->Member->find('first', array('conditions' => array('Member.member_id' => $memberId)));
			$currentMailingLists = $this->_get_mailing_lists_and_subscruibed_status($memberInfo);

			foreach ($currentMailingLists as $mailingList) 
			{
				# Does the member want to be want to be subscribed to this list?
				$wantToBeSubscribed = in_array($mailingList['id'], $subscribeToLists);
				if($wantToBeSubscribed != $mailingList['subscribed'])
				{
					# Need to edit the subscription
					if($wantToBeSubscribed)
					{
						$this->MailChimp->subscribe($mailingList['id'], $memberInfo['Member']['email']);
    					if($this->MailChimp->error_code())
    					{
    						$resultMessage .= 'Unable to subscribe to: ' . $mailingList['name'] . ' because ' . $this->MailChimp->error_msg() . '</br>';
    					}
    					else
    					{
    						$resultMessage .= 'E-mail confirmation of mailing list subscription for: ' . $mailingList['name'] . ' has been sent.' . '</br>';	
    					}
					}
					else
					{
						$this->MailChimp->unsubscribe($mailingList['id'], $memberInfo['Member']['email']);
    					if($this->MailChimp->error_code())
    					{
    						$resultMessage .= 'Unable to un-subscribe from: ' . $mailingList['name'] . ' because ' . $this->MailChimp->error_msg() . '</br>';
    					}
    					else
    					{
    						$resultMessage .= 'Un-Subscribed from: ' . $mailingList['name'] . '</br>';
    					}
					}
				}
			}

			return $resultMessage;
		}

		private function _get_mailing_lists_and_subscruibed_status($memberInfo)
		{
			$mailingListsRet = $this->MailChimp->list_mailinglists();
		    if(!$this->MailChimp->error_code())
		    {
		    	$mailingLists = $mailingListsRet['data'];

		    	if($memberInfo != null)
		    	{
			    	$numMailingLists = count($mailingLists);
			    	for($i = 0; $i < $numMailingLists; $i++)
			    	{
			    		// Grab the list of subscribed members
			    		$subscribedMembers = $this->MailChimp->list_subscribed_members($mailingLists[$i]['id']);
			    		if(!$this->MailChimp->error_code())
			    		{
			    			// Extract the emails
			    			$emails = Hash::extract($subscribedMembers['data'], '{n}.email');
			    			// Are we subscribed to this list?
			    			$mailingLists[$i]['subscribed'] = (in_array($memberInfo['Member']['email'], $emails));
			    			if($i > 0)
			    			{
			    				$mailingLists[$i]['subscribed'] = rand() % 2 == 0;
			    			}
			    			// Can we view it?
			    			$mailingLists[$i]['canView'] = $this->AuthUtil->is_authorized('mailinglists', 'view', array( $mailingLists[$i]['id'] ));
			    		}
			    	}
			    }
		    	return $mailingLists;
		    }
		    return null;
		}

		public function email_members_with_status($status) 
		{

			Controller::loadModel('MemberEmail');

			$members = $this->Member->find('all', array('conditions' => array( 'Member.member_status' => $status )));
			$memberEmails = Hash::extract( $members, '{n}.Member.email' );

			$statusName = "Unknown";
			$statusId = $status;
			$statusList = $this->Member->Status->find( 'all', array( 'conditions' => array( 'status_id' => $status ) ) );
			if(count($statusList) > 0)
			{
				$statusName = $statusList[0]['Status']['title'];
			}

			$this->set('members', $members);
			$this->set('statusName', $statusName);
			$this->set('statusId', $status);

			if ($this->request->is('get')) 
			{
			}
			else 
			{
				$this->MemberEmail->set($this->data);
				if($this->MemberEmail->validates())
				{
					$subject = $this->request->data['MemberEmail']['subject'];
					$message = $this->request->data['Member']['message'];
					if( isset($subject) &&
						$subject != null &&
						strlen(trim($subject)) > 0 &&

						isset($message) &&
						$message != null &&
						strlen(trim($message)) > 0 )
					{
						# Send these out as seperate e-mails
						foreach ($memberEmails as $email) 
						{
							# Send the message out
							$email = $this->prepare_email();
							$email->to($memberEmails);
							$email->subject($subject);
							$email->template('default', 'default');
							$email->send($message);
						}

						$this->Session->setFlash('E-mail sent');
						$this->redirect(array('action' => 'index'));
					}
					else
					{
						$this->Session->setFlash('Unable to send e-mail');
					}
				}
			}
		}


		private function _sendEmail($to, $subject, $template, $viewVars = array())
		{
			if($this->email == null)
			{
				$this->email = new CakeEmail();
			}

			$email = $this->email;
			$email->config('smtp');
			$email->from(array('membership@nottinghack.org.uk' => 'Nottinghack Membership'));
			$email->sender(array('membership@nottinghack.org.uk' => 'Nottinghack Membership'));
			$email->emailFormat('html');
			$email->to($to);
			$email->subject($subject);
			$email->template($template);
			$email->viewVars($viewVars);
			return $email->send();
		}

		public function login() 
		{
		    if ($this->request->is('post')) 
		    {
		        if ($this->Auth->login()) 
		        {
		        	$memberInfo = AuthComponent::user();
		        	# Set the last login time
		        	unset($memberInfo['MemberAuth']);
		        	$memberInfo['MemberAuth']['member_id'] = $memberInfo['Member']['member_id'];
		        	$memberInfo['MemberAuth']['last_login'] = date( 'Y-m-d H:m:s' );
		        	$this->Member->MemberAuth->save($memberInfo);
		            $this->redirect($this->Auth->redirect());
		        } 
		        else 
		        {
		            $this->Session->setFlash(__('Invalid username or password, try again'));
		        }
		    }
		}

		public function logout() 
		{
		    $this->redirect($this->Auth->logout());
		}

		//! Adds the appropriate actions to each member in the member list.
		/*!
			@param array $memberList A list of member summaries to add the actions to.
			@retval array The original memberList, with the actions added for each member.
		*/
		private function _addMemberActions($memberList)
		{
			// Have to add the actions ourselves
	    	for($i = 0; $i < count($memberList); $i++)
	    	{
	    		$actions = array();

	    		$status = $memberList[$i]['status']['id'];
	    		$id = $memberList[$i]['id'];

	    		switch($status)
	    		{
	    			case Status::PROSPECTIVE_MEMBER:
	    				array_push($actions, 
	    					array(
	    						'title' => 'Send Membership Reminder',
	    						'controller' => 'members',
	    						'action' => 'send_membership_reminder',
	    						'params' => array(
	    							$id,
	    						),
	    					)
	    				);
	    			break;

	    			case Status::PRE_MEMBER_1:
	    			break;

	    			case Status::PRE_MEMBER_2:
	    				array_push($actions, 
	    					array(
	    						'title' => 'Send Contact Details Reminder',
	    						'controller' => 'members',
	    						'action' => 'send_contact_details_reminder',
	    						'params' => array(
	    							$id,
	    						),
	    					)
	    				);
	    			break;

	    			case Status::PRE_MEMBER_3:

	    				array_push($actions, 
	    					array(
	    						'title' => 'Send SO Details Reminder',
	    						'controller' => 'members',
	    						'action' => 'send_so_details_reminder',
	    						'params' => array(
	    							$id,
	    						),
	    					)
	    				);

	    				array_push($actions, 
	    					array(
	    						'title' => 'Approve Member',
	    						'controller' => 'members',
	    						'action' => 'approve_member',
	    						'params' => array(
	    							$id,
	    						),
	    					)
	    				);

	    			break;

	    			case Status::CURRENT_MEMBER:
	    				array_push($actions, 
	    					array(
	    						'title' => 'Revoke Membership',
	    						'controller' => 'members',
	    						'action' => 'set_member_status',
	    						'params' => array(
	    							$id,
	    							Status::EX_MEMBER,
	    						),
	    					)
	    				);
	    			break;

	    			case Status::EX_MEMBER:
	    				array_push($actions, 
	    					array(
	    						'title' => 'Reinstate Membership',
	    						'controller' => 'members',
	    						'action' => 'set_member_status',
	    						'params' => array(
	    							$id,
	    							Status::CURRENT_MEMBER,
	    						),
	    					)
	    				);
	    			break;
	    		}

	    		$memberList[$i]['actions'] = $actions;
	    	}

	    	return $memberList;
		}

		//! Get the id of the currently logged in Member.
		/*!
			@retval int The id of the currently logged in Member, or 0 if not found.
		*/
		private function _getLoggedInMemberId()
		{
			return $this->Member->getIdForMember($this->Auth->user());
		}
	}
?>