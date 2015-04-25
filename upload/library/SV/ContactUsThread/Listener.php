<?php

class SV_ContactUsThread_Listener
{
    const AddonNameSpace = 'SV_ContactUsThread';

    public static function load_class($class, array &$extend)
    {
        $extend[] = self::AddonNameSpace.'_'.$class;
    }
}