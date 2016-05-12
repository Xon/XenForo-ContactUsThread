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

    public function actionContact()
    {
        $options = XenForo_Application::getOptions();

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
            $forum = $nodeId ? null : $this->_getForumModel()->getForumById($nodeId);
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
            }
        }

        $parent = parent::actionContact();

        if (!empty($nodeId) && $this->_request->isPost() && $parent instanceof XenForo_ControllerResponse_Redirect)
        {
            $input = $this->_input->filter(array(
                'subject' => XenForo_Input::STRING,
                'message' => XenForo_Input::STRING,
                'email' => XenForo_Input::STRING,
            ));
            $input['ip'] = $this->_request->getClientIp(false);
            $input['username'] = $user['username'];

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
        }
        return $parent;
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

    public function assertNotFlooding($action, $floodingLimit = null)
    {
        if ($action == 'contact' && !XenForo_Visitor::getInstance()->hasPermission('general', 'bypassFloodCheck'))
        {
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

    protected function _getForumModel()
    {
        return $this->getModelFromCache('XenForo_Model_Forum');
    }
}