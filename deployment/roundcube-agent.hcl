vault {
  address = "http://openbao_openbao:8200"
}

auto_auth {
  method "approle" {
    mount_path = "auth/approle"

    config = {
      role_id_file_path                   = "/run/secrets/roundcube_bao_role_id"
      secret_id_file_path                 = "/run/secrets/roundcube_bao_secret_id"

      # Swarm secrets are read-only, so Agent cannot delete this file.
      remove_secret_id_file_after_reading = false
    }
  }

  # Renewable runtime token used by Roundcube to read mailbox credentials.
  sink "file" {
    config = {
      path = "/run/app-secrets/openbao-token"
      mode = 0444
    }
  }
}

template_config {
  static_secret_render_interval = "5m"
  exit_on_retry_failure         = true
}

# Existing Roundcube encryption key. Do not rotate this value casually.
template {
  contents             = "{{ with secret \"kv/data/roundcube/des_key\" }}{{ .Data.data.config }}{{ end }}"
  destination          = "/run/app-secrets/roundcube_des_key"
  create_dest_dirs     = false
  error_on_missing_key = true
  perms                = "0444"
  backup               = false
}

# Authentik OIDC client secret consumed by the Roundcube plugin.
template {
  contents             = "{{ with secret \"kv/data/roundcube/oidc\" }}{{ .Data.data.client_secret }}{{ end }}"
  destination          = "/run/app-secrets/oidc-client-secret"
  create_dest_dirs     = false
  error_on_missing_key = true
  perms                = "0444"
  backup               = false
}
