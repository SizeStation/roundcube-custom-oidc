pid_file = "/tmp/openbao-agent.pid"

vault {
  address = "https://bao.sizestation.cloud"
  ca_cert = "/etc/ssl/certs/ca-certificates.crt"
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
      mode = 0644
    }
  }
}

template_config {
  exit_on_retry_failure = true
  static_secret_render_interval = "5m"
}

template {
  destination          = "/run/app-secrets/roundcube_des_key"
  perms                = "0644"
  backup               = false
  error_on_missing_key = true
  contents             = "{{ with secret \"kv/data/roundcube/des_key\" }}{{ .Data.data.config }}{{ end }}"
}

template {
  destination          = "/run/app-secrets/oidc-client-secret"
  perms                = "0644"
  backup               = false
  error_on_missing_key = true
  contents             = "{{ with secret \"kv/data/roundcube/oidc\" }}{{ .Data.data.client_secret }}{{ end }}"
}
