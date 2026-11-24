# PikaRelay

Web Based Relay for PikaReader

Objective is to allow PikaReader systems to be able to use this web based relay to asynchronously transmit data to PikaDownloader clients.

PikaReader systems will post the reads

* PikaReader source code is in ../PikaReader
* PikaDownloader source code is in ../PikaDownloader

## Proposed API
- / - Landing Page. Shows admin login for unauthenticated users or table of readers by name and the last time they posted a status update. 
- GET /readerStatus/<reader id>  – Return latest status json
- PUT /readerStatus/<reader id>
- GET /readerList  - Return list of known readers w/ last update timestamp
- POST /putReads/<reader id>  - Upload reads
- GET /getReads/<reader id>/<epochmilli>  - get reads since <epochmilli> for <reader> 
- POST /commands/<reader id>  - post a command to a reader (start, rewind, etc)
- GET /commands/<reader id>
- DELETE /commands/<reader id>/<command id>
- /admin – Admin protected page to
  - clear old reads
  - delete old readers
  - Remove old commands
  - Create / delete api keys for readers and clients
- REST Commands optionally require an api key. 
- Admin Commands require username / password
- Setup script to create DB, set pw, etc. 

---

## Notes
* System should be able to support php v7.4 -> 8.4 running on either apache httpd, lightHttpd, or nginx. 
* This is an internet facing system. The scripts should validate all input recieved and sanatize as appropriate. If inappropriate commands or data is posted, the system should not attempt to sanitize it but should reject it with a 422 bad Request or similar error. 
* Requests and posted data from PikaReader clients is in a basic json format. The keys may change over time but will never contain escape charachters. Requests from PikaDownloader clients uses a fixed vocabulary that is defined in advance. 


In "secure mode," readers and clients will need to provide an API key / secret to post or read data.  In insecure mode, all requests to post or read data are unauthenticated. Admin access always requires an admin username / password which is stored in the config file. 

If a user forgets the admin username, they can delete the encrypted has from the config file. The system will then accept a blank password and immediately prompt to set a new password. 

If the system is running in secure mode, the admin page will allow the administrator to create API keys and secrets for both PikaReader systems and PikaDownloader clients. 
The create function will default to displaying a suggested key/secret but the user can change it to any 4+ charachter string before saving. 
The administrator can create multiple keys and secrets. 
The system employs a basic RBAC control in that PikaReader keys can be used to post a reader status, post reads, retrieve / acknowledge commands. PikaDownloader keys/secrets can be used to read reads and post commands for a reader. 

## Setup Script
Upon initial upload of the scripts to the remote server, users can go to the base URL and be presented with a 
setup page. Here they can enter the admin password, database connection information, and optionally if the system should run in "secure mode" or "insecure mode"

The setup script  will validate the database connection information, create the configuration file, disable the setup script, and place the new admin login page 


