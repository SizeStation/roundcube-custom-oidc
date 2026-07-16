pid_file = "/tmp/openbao-agent.pid"

vault {
  address = "https://openbao:8200"
  ca_cert = "/etc/openbao/ca/openbao-ca.pem"
}

auto_auth {
  method "approle" {
    mount_path = "auth/approle"
    config = {
      role_id_file_path                   = "/run/secrets/roundcube_bao_role_id"
      secret_id_file_path                 = "/run/secrets/roundcube_bao_secret_id"
      remove_secret_id_file_after_reading = false
    }
  }

  sink "file" {
    config = {
      path = "/run/app-secrets/openbao-token"
      mode = 0640
      uid  = 33
      gid  = 33
    }
  }
}

template_config {
  exit_on_retry_failure = true
  static_secret_render_interval = "5m"
}

template {
  destination          = "/run/app-secrets/roundcube-des-key"
  perms                = "0640"
  backup               = false
  error_on_missing_key = true
  contents             = "{{ with secret \"kv/data/roundcube/config\" }}{{ .Data.data.des_key }}{{ end }}"
}

template {
  destination          = "/run/app-secrets/oidc-client-secret"
  perms                = "0640"
  backup               = false
  error_on_missing_key = true
  contents             = "{{ with secret \"kv/data/roundcube/config\" }}{{ .Data.data.oidc_client_secret }}{{ end }}"
}
