# NKTI BIOMED MedTracker — LAN Deployment Guide
### Fully Offline System for Government Use
---

## FOLDER STRUCTURE

```
nkti-biomed/
├── .htaccess                  ← Apache security rules
├── api/
│   ├── bootstrap.php          ← Shared helpers, auth, session
│   ├── auth.php               ← Login / logout / user management
│   └── equipment.php          ← Equipment CRUD + bulk import
├── config/
│   └── database.php           ← DB credentials (KEEP PRIVATE)
├── public/
│   ├── index.html             ← Your frontend HTML file
│   └── assets/
│       └── api-client.js      ← Frontend ↔ Backend bridge
├── sql/
│   └── schema.sql             ← Database tables
└── docs/
    └── README.md              ← This file
```

---

## STEP 1 — INSTALL XAMPP (Windows) or LAMP (Linux)

### Windows — XAMPP
1. Download XAMPP from https://www.apachefriends.org (choose PHP 8.1+)
2. Install to `C:\xampp`
3. Open **XAMPP Control Panel** → Start **Apache** and **MySQL**
4. Verify: open browser → `http://localhost` → should show XAMPP dashboard

### Linux (Ubuntu/Debian) — LAMP
```bash
sudo apt update
sudo apt install apache2 php php-mysql php-json libapache2-mod-php mysql-server -y
sudo systemctl enable apache2 mysql
sudo systemctl start apache2 mysql
```

### Linux (CentOS/RHEL)
```bash
sudo yum install httpd php php-mysqlnd php-json mysql-server -y
sudo systemctl enable httpd mysqld
sudo systemctl start httpd mysqld
sudo mysql_secure_installation
```

---

## STEP 2 — COPY PROJECT FILES

### Windows (XAMPP)
```
Copy the entire nkti-biomed/ folder to:
C:\xampp\htdocs\nkti-biomed\
```

### Linux
```bash
sudo cp -r nkti-biomed/ /var/www/html/
sudo chown -R www-data:www-data /var/www/html/nkti-biomed/
sudo chmod -R 755 /var/www/html/nkti-biomed/
sudo chmod 640 /var/www/html/nkti-biomed/config/database.php
```

---

## STEP 3 — SET UP MYSQL DATABASE

### Windows (XAMPP)
1. Open browser → `http://localhost/phpmyadmin`
2. Click **SQL** tab
3. Paste contents of `sql/schema.sql` → click **Go**

OR via command line:
```
C:\xampp\mysql\bin\mysql.exe -u root -p < sql\schema.sql
```

### Linux
```bash
sudo mysql -u root -p < /var/www/html/nkti-biomed/sql/schema.sql
```

### Create dedicated MySQL user (recommended for security)
```sql
-- Run this in phpMyAdmin SQL tab or MySQL console:
CREATE USER 'nkti_user'@'localhost' IDENTIFIED BY 'StrongPass!2025';
GRANT SELECT, INSERT, UPDATE, DELETE ON nkti_biomed.* TO 'nkti_user'@'localhost';
FLUSH PRIVILEGES;
```

---

## STEP 4 — CONFIGURE database.php

Open `config/database.php` and update:
```php
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'nkti_user');       // MySQL username you created
define('DB_PASS', 'StrongPass!2025'); // Change this to your password!
define('DB_NAME', 'nkti_biomed');
```

---

## STEP 5 — UPDATE FRONTEND HTML

In your `public/index.html` (the MedTracker frontend), make these changes:

### A) Add the API client script before </body>
```html
<script src="/nkti-biomed/public/assets/api-client.js"></script>
```

### B) Replace all localStorage calls
The frontend currently uses localStorage. The api-client.js provides
drop-in replacements. In index.html, update these function calls:

| Old (localStorage)  | New (database)            |
|---------------------|---------------------------|
| doLogin()           | doLoginServer()           |
| doLogout()          | doLogoutServer()          |
| saveEquipment()     | saveEquipmentToServer()   |
| deleteEquipment(id) | deleteEquipmentFromServer(id) |
| confirmImport()     | confirmImportToServer()   |
| addUser()           | addUserServer()           |

### C) Update the API base URL in api-client.js
```javascript
// For local testing:
const API_BASE = '/nkti-biomed/api';

// For LAN access (replace with your server's LAN IP):
const API_BASE = 'http://192.168.1.100/nkti-biomed/api';
```

---

## STEP 6 — ASSIGN STATIC LAN IP TO SERVER

### Windows
1. Open **Control Panel → Network → Change adapter settings**
2. Right-click your LAN adapter → **Properties**
3. Select **Internet Protocol Version 4 (TCP/IPv4)** → Properties
4. Choose **Use the following IP address:**
   - IP address: `192.168.1.100`  (choose unused IP on your network)
   - Subnet mask: `255.255.255.0`
   - Default gateway: `192.168.1.1`  (your router IP)
5. Click OK → OK

### Linux (Ubuntu 20.04+, using netplan)
```bash
sudo nano /etc/netplan/00-installer-config.yaml
```
Paste:
```yaml
network:
  version: 2
  ethernets:
    eth0:          # replace with your interface name (ip a to check)
      dhcp4: false
      addresses:
        - 192.168.1.100/24
      gateway4: 192.168.1.1
      nameservers:
        addresses: [8.8.8.8]
```
```bash
sudo netplan apply
```

### Verify
```bash
# On the server:
ip a   # should show 192.168.1.100

# From another PC on the same network:
ping 192.168.1.100
```

---

## STEP 7 — CONFIGURE FIREWALL

### Windows Firewall
1. Open **Windows Defender Firewall → Advanced Settings**
2. **Inbound Rules → New Rule**
3. Rule type: **Port** → TCP → **Specific local ports: 80**
4. Action: **Allow** → Apply to all profiles → Name: `NKTI BIOMED HTTP`
5. Repeat for port **443** if using HTTPS later

Or via Command Prompt (run as Administrator):
```cmd
netsh advfirewall firewall add rule name="NKTI Apache HTTP" protocol=TCP dir=in localport=80 action=allow
netsh advfirewall firewall add rule name="NKTI MySQL"      protocol=TCP dir=in localport=3306 action=block
```
(Block MySQL port from network — DB should only be accessed locally)

### Linux (UFW)
```bash
sudo ufw allow 80/tcp        # HTTP
sudo ufw deny 3306/tcp       # Block MySQL from LAN (access locally only)
sudo ufw enable
sudo ufw status
```

### Linux (firewalld - CentOS/RHEL)
```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --remove-service=mysql
sudo firewall-cmd --reload
```

---

## STEP 8 — TEST THE SYSTEM

1. From the server: open `http://localhost/nkti-biomed/public/`
2. From another PC on the same LAN: open `http://192.168.1.100/nkti-biomed/public/`
3. Login with: **admin / admin123**
4. ⚠️ Immediately change the default admin password after first login!

---

## DEFAULT ACCOUNTS

| Username  | Password  | Role     | Location            |
|-----------|-----------|----------|---------------------|
| admin     | admin123  | Admin    | All                 |

> Admin creates additional users from the 👥 Users panel inside the app.

---

## SECURITY CHECKLIST

- [ ] Change default admin password
- [ ] Use a strong MySQL password in config/database.php
- [ ] Ensure config/ and sql/ directories are not web-accessible (.htaccess handles this)
- [ ] MySQL port 3306 is blocked at firewall
- [ ] Do NOT expose this system to the public internet — LAN only
- [ ] Back up the database weekly:
      `mysqldump -u nkti_user -p nkti_biomed > backup_$(date +%Y%m%d).sql`

---

## TROUBLESHOOTING

| Problem                        | Solution                                              |
|-------------------------------|-------------------------------------------------------|
| "Database connection failed"   | Check config/database.php credentials                 |
| "Unauthorized" on every call   | PHP session cookie not working — check .htaccess      |
| 404 on API calls               | Apache mod_rewrite not enabled: `a2enmod rewrite`     |
| Can't reach from other PC      | Check firewall (Step 7) and confirm IP (Step 6)       |
| Charts not showing             | CDN blocked? Add Chart.js locally to public/assets/   |

### Enable mod_rewrite (Linux)
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Check PHP version
```bash
php --version   # Must be 7.4 or higher (8.1+ recommended)
```
