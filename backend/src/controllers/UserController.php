<?php

namespace M133\Controllers;

class UserController extends \M133\Controller {
    public function getUser( $user_id ) {
        return 0;
    }

    public function addUser( $userinfo ) {
        $user_sql = "INSERT INTO users (first_name, last_name, birthdate, club_id, username, password, email, is_active, is_verified) VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?)";

        $this->db->changeData( $user_sql, [
            $userinfo['first_name'],
            $userinfo['last_name'],
            $userinfo['birthdate'],
            NULL,
            $userinfo['username'],
            $userinfo['password'],
            $userinfo['email'],
            true,
            true
        ], "User " . $userinfo['username']);
    }

    public function usernameTaken( $username ) {

        $existance_sql = "SELECT username FROM users WHERE username = ?";

        $data = $this->db->queryData($existance_sql, [$username], "Username " . $username)[0];

        if ( ! empty($data) &&
            $data['username'] == $username)
            return true;
        return false;
    }

    public function validCreds( $username, $password ) {

        $user_sql = "SELECT username, password FROM users WHERE username = ?";
        $data = $this->db->queryData($user_sql, [$username], "Username " . $username)[0];

        if ($data['username'] == $username && 
        password_verify( $password, $data['password']))
            return true;
        return false;
    }
}