<?php

class SV_ContactUsThread_XenForo_Model_SpamPrevention extends XFCP_SV_ContactUsThread_XenForo_Model_SpamPrevention
{
    public function getUserLogsByIpOrEmail($ip, $email, $limit = 5)
    {
        $ip = XenForo_Helper_Ip::convertIpStringToBinary($ip);

        return $this->fetchAllKeyed(
            $this->limitQueryResults(
                "SELECT log.*, user.*
                    FROM xf_spam_trigger_log AS log
                    LEFT JOIN xf_user AS user ON (log.user_id = user.user_id)
                    WHERE log.content_type = 'user'
                        AND (log.ip_address = ? OR user.email = ?)
                    ORDER BY log.log_date DESC",
                $limit
            ),
            'trigger_log_id',
            array($ip, $email)
        );
    }
}
