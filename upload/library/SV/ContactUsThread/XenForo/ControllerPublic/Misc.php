<?php

class SV_ContactUsThread_XenForo_ControllerPublic_Misc extends XFCP_SV_ContactUsThread_XenForo_ControllerPublic_Misc
{
    var $action = null;

    protected function _preDispatchFirst($action)
    {
        $this->action = strtolower($action);
        parent::_preDispatchFirst($action);
    }


    protected function _assertNotBanned()
    {
        if ($this->action == 'contact' && XenForo_Application::getOptions()->sv_banned_user_can_use_contactus_form)
        {
            return;
        }
        parent::_assertNotBanned();
    }

    public function canUpdateSessionActivity($controllerName, $action, &$newState)
    {
        if (strtolower($action) == 'contact')
        {
            return true;
        }

        return parent::canUpdateSessionActivity($controllerName, $action, $newState);
    }

    public function actionContact()
    {
        $options = XenForo_Application::getOptions();

        if ($options->sv_discardcontactusmessage && $this->_request->isPost() && $this->_isDiscouraged())
        {
            return $this->responseRedirect(
                XenForo_ControllerResponse_Redirect::SUCCESS,
                $this->getDynamicRedirect(),
                new XenForo_Phrase('your_message_has_been_sent')
            );
        }

        if ($options->contactUrl['type'] == 'custom')
        {
            return $this->responseRedirect(
                XenForo_ControllerResponse_Redirect::RESOURCE_CANONICAL,
                $options->contactUrl['custom']
            );
        }
        else if (!$options->contactUrl['type'])
        {
            return $this->responseRedirect(
                XenForo_ControllerResponse_Redirect::RESOURCE_CANONICAL,
                XenForo_Link::buildPublicLink('index')
            );
        }

        $nodeId = 0;
        $default_prefix_id = 0;
        if ($this->_request->isPost())
        {
            $nodeId = $options->sv_contactusthread_node;
            $forum = $this->_getForumModel()->getForumById($nodeId);
            if (empty($forum))
            {
                $nodeId = 0;
            }
            if (!empty($nodeId))
            {
                $user = XenForo_Visitor::getInstance()->toArray();
                $username = $user['username'];
                if(empty($user['user_id']))
                {
                    $this->_verifyUsername($username);
                }
                $default_prefix_id = $forum['default_prefix_id'];

                $this->assertNotFlooding('contact');
                $this->blockFloodCheck = true;

                if ($options->sv_contactus_spamCheck)
                {
                    // setup spam check
                    $input = $this->_input->filter(array(
                        'subject' => XenForo_Input::STRING,
                        'message' => XenForo_Input::STRING,
                        'email' => XenForo_Input::STRING,
                    ));
                    if (!empty($user['user_id']))
                    {
                        $input['email'] = $user['email'];
                    }
                    $input['message'] = $input['subject'] . "\n" . $input['message'];
                    $visitor['username'] = $username;
                    $visitor['email'] = $input['email'];
                    $spamModel = $this->_getSpamPreventionModel();
                    switch ($spamModel->checkMessageSpam($input['message'], array(), $this->_request))
                    {
                        case XenForo_Model_SpamPrevention::RESULT_MODERATED:
                        case XenForo_Model_SpamPrevention::RESULT_DENIED;
                            $spamModel->logSpamTrigger('contact_us', null);
                            throw new XenForo_Exception(new XenForo_Phrase('your_content_cannot_be_submitted_try_later'), true);
                            break;
                    }
                }
            }
        }

        $parent = parent::actionContact();

        if (!empty($nodeId) && $this->_request->isPost() && $parent instanceof XenForo_ControllerResponse_Redirect)
        {
            $this->sv_postThread($nodeId, $default_prefix_id, $user, $username);
        }
        return $parent;
    }

    protected function sv_postThread($nodeId, $default_prefix_id, array $user, $username)
    {
        $options = XenForo_Application::getOptions();
        $input = $this->_input->filter(array(
            'subject' => XenForo_Input::STRING,
            'message' => XenForo_Input::STRING,
            'email' => XenForo_Input::STRING,
        ));
        $input['ip'] = $this->_request->getClientIp(false);
        $input['username'] = $username;
        if (!empty($user['user_id']))
        {
            $input['email'] = $user['email'];
        }

        $spamTriggerLogDays = $options->sv_contactusthread_spamtriggerlogdays;
        $spamTriggerLogLimit = $options->sv_contactusthread_spamtriggerloglimit;

        if ($spamTriggerLogDays != 0 && $spamTriggerLogLimit != 0)
        {
            $spamPreventionModel = $this->_getSpamPreventionModel();

            $logs = $spamPreventionModel->prepareSpamTriggerLogs(
                $spamPreventionModel->getUserLogsByIpOrEmail(
                    $input['ip'],
                    $input['email'],
                    $spamTriggerLogDays,
                    $spamTriggerLogLimit
                )
            );
        }
        else
        {
            $logs = array();
        }

        $input['spam_trigger_logs'] = $this->_formatLogsForDisplay($logs);

        $db = XenForo_Application::getDb();

        if(empty($user['user_id']))
        {
            $message =  new XenForo_Phrase('ContactUs_Message_Guest', $input, false);
        }
        else
        {
            $message = new XenForo_Phrase('ContactUs_Message_User', $input, false);
        }

        $threadDw = XenForo_DataWriter::create('XenForo_DataWriter_Discussion_Thread'); //, XenForo_DataWriter::ERROR_SILENT
        $threadDw->setOption(XenForo_DataWriter_Discussion::OPTION_TRIM_TITLE, true);
        $threadDw->bulkSet(array(
            'user_id' => $user['user_id'],
            'username' => $username,
            'title' => $input['subject'],
            'node_id' => $nodeId,
            'discussion_state' => 'visible',
            'prefix_id' => $default_prefix_id,
        ));

        $postWriter = $threadDw->getFirstMessageDw();
        $postWriter->setOption(XenForo_DataWriter_DiscussionMessage::OPTION_VERIFY_GUEST_USERNAME, false);
        $postWriter->set('message', $message);
        $threadDw->save();
        return $threadDw;
    }

    // Based off from XenForo_DataWriter_User::_verifyUsername
    protected function _verifyUsername($username)
    {
        $options = XenForo_Application::get('options');

        // standardize white space in names
        $username = preg_replace('/\s+/u', ' ', $username);
        try
        {
            // if this matches, then \v isn't known (appears to be PCRE < 7.2) so don't strip
            if (!preg_match('/\v/', 'v'))
            {
                $newName = preg_replace('/\v+/u', ' ', $username);
                if (is_string($newName))
                {
                    $username = $newName;
                }
            }
        }
        catch (Exception $e) {}

        $username = trim($username);

        $usernameLength = utf8_strlen($username);
        $minLength = intval($options->get('usernameLength', 'min'));
        $maxLength = intval($options->get('usernameLength', 'max'));

        if ($minLength > 0 && $usernameLength < $minLength)
        {
            throw new XenForo_Exception(new XenForo_Phrase('please_enter_name_that_is_at_least_x_characters_long', array('count' => $minLength)), true);
        }
        if ($maxLength > 0 && $usernameLength > $maxLength)
        {
            throw new XenForo_Exception(new XenForo_Phrase('please_enter_name_that_is_at_most_x_characters_long', array('count' => $maxLength)), true);
        }

        $disallowedNames = preg_split('/\r?\n/', $options->get('usernameValidation', 'disallowedNames'));
        if ($disallowedNames)
        {
            foreach ($disallowedNames AS $name)
            {
                $name = trim($name);
                if ($name === '')
                {
                    continue;
                }
                if (stripos($username, $name) !== false)
                {
                    throw new XenForo_Exception(new XenForo_Phrase('please_enter_another_name_disallowed_words'), true);
                }
            }
        }

        $matchRegex = $options->get('usernameValidation', 'matchRegex');
        if ($matchRegex)
        {
            $matchRegex = str_replace('#', '\\#', $matchRegex); // escape delim only
            if (!preg_match('#' . $matchRegex . '#i', $username))
            {
                throw new XenForo_Exception(new XenForo_Phrase('please_enter_another_name_required_format'), true);
            }
        }

        $censoredUserName = XenForo_Helper_String::censorString($username);
        if ($censoredUserName !== $username)
        {
            throw new XenForo_Exception(new XenForo_Phrase('please_enter_name_that_does_not_contain_any_censored_words'), true);
        }

        // ignore check if unicode properties aren't compiled
        try
        {
            if (@preg_match("/\p{C}/u", $username))
            {
                throw new XenForo_Exception(new XenForo_Phrase('please_enter_name_without_using_control_characters'), true);
            }
        }
        catch (Exception $e) {}

        if (strpos($username, ',') !== false)
        {
            throw new XenForo_Exception(new XenForo_Phrase('please_enter_name_that_does_not_contain_comma'), true);
        }

        if (XenForo_Helper_Email::isEmailValid($username))
        {
            throw new XenForo_Exception(new XenForo_Phrase('please_enter_name_that_does_not_resemble_an_email_address'), true);
        }
    }

    protected $blockFloodCheck = false;

    public function assertNotFlooding($action, $floodingLimit = null)
    {
        if ($action == 'contact' && !XenForo_Visitor::getInstance()->hasPermission('general', 'bypassFloodCheck'))
        {
            if ($this->blockFloodCheck)
            {
                return;
            }
            $contactFloodingLimit = XenForo_Application::getOptions()->sv_contactusthread_ratelimit;
            if (!$contactFloodingLimit)
            {
                $contactFloodingLimit = $floodingLimit;
            }
            $userId = XenForo_Visitor::getUserId();
            if (!$userId)
            {
                // xf_flood_check.user_id is unsigned 32 bits integer.
                // Use the IP address crc32'ed as a stand-in for the userid to fit into the field.
                // set the high bit to ensure it is unlikely to cause a collision with a valid user
                $userId = crc32(XenForo_Helper_Ip::getBinaryIp(null, null)) | (1 << 31);
            }

            $floodTimeRemaining = XenForo_Model_FloodCheck::checkFlooding($action, $contactFloodingLimit, $userId);
            if ($floodTimeRemaining)
            {
                throw $this->responseException(
                    $this->responseFlooding($floodTimeRemaining)
                );
            }
            return;
        }
        parent::assertNotFlooding($action, $floodingLimit);
    }

    protected function _formatLogsForDisplay(array $logs)
    {
        if (!empty($logs))
        {
            $logOutput = "[LIST]\n";

            foreach ($logs as $log)
            {
                $time = XenForo_Locale::dateTime($log['log_date'], 'absolute');
                $logOutput .= "[*]{$time}: ";

                if ($log['username'])
                {
                    $logOutput .= "@{$log['username']} ";
                }
                else
                {
                    $logOutput .= new XenForo_Phrase('unknown_account').' ';
                }

                $logOutput .= ' - ';

                if ($log['result'] ==  'denied')
                {
                    $result = new XenForo_Phrase('rejected');
                }
                elseif ($log['result'] == 'moderated')
                {
                    $result = new XenForo_Phrase('moderated');
                }
                else
                {
                    $result = $log['result'];
                }

                $logOutput .= $result;

                foreach ($log['detailsPrintable'] as $detail)
                {
                    $logOutput .= " ({$detail})";
                }

                $logOutput .= "\n";
            }

            $logOutput .= '[/LIST]';
        }
        else
        {
            $logOutput = new XenForo_Phrase(
                'sv_contactusthread_no_matching_spam_trigger_logs'
            );
        }

        return $logOutput;
    }

    protected function _getForumModel()
    {
        return $this->getModelFromCache('XenForo_Model_Forum');
    }

    /**
     * @return XenForo_Model_SpamPrevention
     */
    protected function _getSpamPreventionModel()
    {
        return $this->getModelFromCache('XenForo_Model_SpamPrevention');
    }
}
