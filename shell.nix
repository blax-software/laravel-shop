{ pkgs ? import <nixpkgs> {} }:

pkgs.mkShell {
  buildInputs = with pkgs; [
    php82
    php82Packages.composer
    php82Extensions.dom
    php82Extensions.mbstring
    php82Extensions.xml
    php82Extensions.xmlwriter
    php82Extensions.tokenizer
    php82Extensions.pdo
    php82Extensions.pdo_sqlite
    php82Extensions.sqlite3
    php82Extensions.json
    php82Extensions.libxml
    php82Extensions.curl
    php82Extensions.openssl
  ];

  shellHook = ''
    echo "Laravel Package Test Environment"
    echo "PHP version: $(php --version | head -n 1)"
    echo ""
    echo "Run tests with: composer test"
  '';
}
