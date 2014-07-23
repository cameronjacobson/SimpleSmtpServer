# SimpleSmtpServer Examples

`run.php` will instantiate the server

`client.php`, `send_attachment.php`, `ssl_client.php`, `tls_client.php` will run swiftmailer client as a Proof of Concept.  Contents of mail will be dumped the "MailStore" that was injected via `run.php`.

The `run.php` script assumes you have CouchDB as your backend MailStore.  However, as long as you inject a `MailStore` object which implements the `MailStoreInterface`, you can swap out the backend storage.

In order to generate your own sample-key.pem, and sample-cert.pem: `openssl req -x509 -newkey rsa:2048 -keyout sample-key.pem -out sample-cert.pem -days 365`
