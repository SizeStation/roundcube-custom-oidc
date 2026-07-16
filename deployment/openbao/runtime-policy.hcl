# Attached only to the renewable Roundcube Agent AppRole token.
path "kv/data/roundcube/des_key" {
  capabilities = ["read"]
}

path "kv/data/roundcube/oidc" {
  capabilities = ["read"]
}

path "kv/data/roundcube/mailboxes/*" {
  capabilities = ["read"]
}
