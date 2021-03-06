<?php

/**
 * @author Michal Prochazka
 * @author Jakub Mlcak
 */
class HostDao
{
    private $db;

    public function __construct(DbManager $dbManager)
    {
        $this->db = $dbManager;
    }

    public function create(Host $host)
    {
        if ($host == null) {
            Utils::log(LOG_ERR, "Exception", __FILE__, __LINE__);
            throw new Exception("Host object is not valid");
        }
        $this->db->query("insert into Host set
            hostname='".$this->db->escape($host->getHostname())."',
            ip='".$this->db->escape($host->getIp())."',
            reporterIp='".$this->db->escape($host->getReporterIp())."',
            reporterHostname='".$this->db->escape($host->getReporterHostname())."',
            kernel='".$this->db->escape($host->getKernel())."',
            osId=".$this->db->escape($host->getOsId()).",
            archId=".$this->db->escape($host->getArchId()).",
            domainId=".$this->db->escape($host->getDomainId()).",
            numOfCves=".$this->db->escape($host->getNumOfCves()).",
            numOfCvesWithTag=".$this->db->escape($host->getNumOfCvesWithTag()).",
            lastReportId=".($host->getLastReportId() == -1 ? "NULL" : $this->db->escape($host->getLastReportId())).",
            pkgTypeId='".$this->db->escape($host->getPkgTypeId())."'");

        # Set the newly assigned id
        $host->setId($this->db->getLastInsertedId());
        Utils::log(LOG_DEBUG, "Host created", __FILE__, __LINE__);
    }

    public function getIdByHostnameIpReporterHostnameReporterIp($hostname, $ip, $reporterHostname, $reporterIp)
    {
        $id = $this->db->queryToSingleValue("select id from Host
            where hostname='".$this->db->escape($hostname)."'
            and ip='".$this->db->escape($ip)."'
            and reporterHostname='".$this->db->escape($reporterHostname)."'
            and reporterIp='".$this->db->escape($reporterIp)."'");
        if ($id == null) {
            return -1;
        }
        return $id;
    }

    public function getById($id, $userId = -1)
    {
        # Try to find the host in the DB
        if (!is_numeric($id)) {
            return null;
        }

        $select = "distinct
            Host.id as _id, 
            Host.hostname as _hostname,
            Host.ip as _ip, 
            Host.reporterIp as _reporterIp,
            Host.reporterHostname as _reporterHostname,
            Host.kernel as _kernel,
            Host.pkgTypeId as _pkgTypeId,
            Host.osId as _osId,
            Host.archId as _archId,
            Host.domainId as _domainId,
            Host.numOfCves as _numOfCves,
            Host.numOfCvesWithTag as _numOfCvesWithTag,
            Host.lastReportId as _lastReportId,
            Arch.name as _archName,
            Os.name as _osName,
            Domain.name as _domainName,
            PkgType.name as _pkgTypeName";
        $from = "Host";
        $join = null;
        $where[] = "Host.id = $id";

        if ($userId != -1) {
            $join[] ="inner join HostHostGroup on HostHostGroup.hostId = Host.id";
            $join[] ="left join UserHostGroup on HostHostGroup.hostGroupId = UserHostGroup.hostGroupId";
            $join[] ="left join UserHost on Host.id = UserHost.hostId";
            $where[] = "(UserHostGroup.userId = $userId or UserHost.userId = $userId)";
        }

        $join[] = "inner join Arch on Host.archId = Arch.id";
        $join[] = "inner join Os on Host.osId = Os.id";
        $join[] = "inner join Domain on Host.domainId = Domain.id";
        $join[] = "inner join PkgType on Host.pkgTypeId = PkgType.id";

        $sql = Utils::sqlSelectStatement($select, $from, $join, $where);

        return $this->db->queryObject($sql, "Host");
    }

    public function getHostsIds($orderBy = null, $pageSize = -1, $pageNum = -1, $search = null, $cveName = null, $tag = null, $hostGroupId = -1, $activity = null, $pkgId = -1, $userId = -1, $directlyAssignedToUser = false)
    {
        $select = "distinct Host.id";
        $from = "Host";
        $join = null;
        $where = null;
        $order = null;
        $limit = null;
        $offset = null;

        # tmpJoin variable indicates whether table is joined
        $tmpJoinHostHostGroup = false;
        $tmpJoinReport = false;
        $tmpJoinOs = false;

        # Because os and arch are ids to other tables, we have to do different sorting
        switch ($orderBy) {
            case "os":
                $join[] = "inner join Os on Host.osId = Os.id";
                $tmpJoinOs = true;
                $order[] = "Os.name";
                break;
            case "arch":
                $join[] = "inner join Arch on Host.archId = Arch.id";
                $order[] = "Arch.name";
                break;
            case "kernel":
                $order[] = "Host.kernel";
                break;
            case "cves":
                $order[] = "Host.numOfCves DESC";
                break;
            case "taggedCves":
                $order[] = "Host.numOfCvesWithTag DESC";
                break;
            case "lastReport":
                $join[] = "left join Report on Host.lastReportId = Report.id";
                $tmpJoinReport = true;
                $order[] = "Report.receivedOn DESC";
                break;
            default:
                break;
        }
        $order[] = "Host.hostname";

        if ($search != null) {
            $search = trim($search);
            if (!$tmpJoinOs) {
                $join[] = "inner join Os on Host.osId = Os.id";
                $tmpJoinOs = true;
            }
            $where[] = "lower(Host.hostname) like '%".$this->db->escape(strtolower($search), true)."%'
                or lower(Os.name) like '%".$this->db->escape(strtolower($search), true)."%'
                or lower(Host.kernel) like '%".$this->db->escape(strtolower($search), true)."%'";
        }

        if ($cveName != null || $tag != null) {
            $join[] = "inner join InstalledPkg on InstalledPkg.hostId = Host.id";
            $join[] = "inner join PkgCveDef on PkgCveDef.pkgId = InstalledPkg.pkgId";
            $join[] = "inner join OsOsGroup on (PkgCveDef.osGroupId = OsOsGroup.osGroupId and OsOsGroup.osId = Host.osId)";
            $join[] = "inner join CveCveDef on PkgCveDef.cveDefId = CveCveDef.cveDefId";
            $join[] = "inner join Cve on CveCveDef.cveId = Cve.id";
            $join[] = "left join CveException on (Cve.name = CveException.cveName and PkgCveDef.pkgId = CveException.pkgId and PkgCveDef.osGroupId = CveException.osGroupId)";
            $where[] = "CveException.id IS NULL";
        }

        if ($cveName != null) {
            $where[] = "Cve.name = '".$this->db->escape($cveName)."'";
        }

        if ($tag != null) {
            if ($tag === true) {
                $join[] = "inner join CveTag on (Cve.name = CveTag.cveName and CveTag.enabled = '1')";
            } else {
                $join[] = "inner join CveTag on (Cve.name = CveTag.cveName and CveTag.enabled = '1' and CveTag.tagName = '" . $this->db->escape($tag) . "')";
            }
        }

        if ($hostGroupId != -1) {
            if (!$tmpJoinHostHostGroup) {
                $join[] = "inner join HostHostGroup on HostHostGroup.hostId = Host.id";
                $tmpJoinHostHostGroup = true;
            }
            $where[] = "HostHostGroup.hostGroupId = '".$this->db->escape($hostGroupId)."'";
        }

        if ($pkgId != -1) {
            $join[] = "inner join InstalledPkg on Host.id = InstalledPkg.hostId";
            $where[] = "InstalledPkg.pkgId = '" . $this->db->escape($pkgId) . "'";
        }

        if ($activity != null) {
            if (!$tmpJoinReport) {
                $join[] = "left join Report on Host.lastReportId = Report.id";
                $tmpJoinReport = true;
            }
            if (preg_match('/^(\+|-|)(\d+)(.|)$/', trim($activity), $matches) === 1) {
                switch ($matches[1]) {
                    case "-":
                        $operator = "<";
                        break;
                    default:
                        $operator = ">";
                        break;
                }
                $number = $matches[2];
                switch ($matches[3]) {
                    case "s":
                        $interval = "second";
                        break;
                    case "m":
                        $interval = "minute";
                        break;
                    case "h":
                        $interval = "hour";
                        break;
                    case "w":
                        $interval = "week";
                        break;
                    case "y":
                        $interval = "year";
                        break;
                    default:
                        $interval = "day";
                        break;
                }
                $where[] = "Report.receivedOn ".$operator." date_sub(now(), interval ".$number." ".$interval.")";
            }
        }

        if ($userId != -1) {
            $join[] = "left join UserHost on Host.id = UserHost.hostId";

            if ($directlyAssignedToUser) {
                $where[] = "UserHost.userId = $userId";
            } else {
                if (!$tmpJoinHostHostGroup) {
                    $join[] = "inner join HostHostGroup on HostHostGroup.hostId = Host.id";
                    $tmpJoinHostHostGroup = true;
                }
                $join[] = "left join UserHostGroup on HostHostGroup.hostGroupId = UserHostGroup.hostGroupId";
                $where[] = "(UserHostGroup.userId = $userId or UserHost.userId = $userId)";
            }
        }

        if ($pageSize != -1 && $pageNum != -1) {
            $limit = $pageSize;
            $offset = $pageSize * $pageNum;
        }

        $sql = Utils::sqlSelectStatement($select, $from, $join, $where, $order, $limit, $offset);

        return $this->db->queryToSingleValueMultiRow($sql);
    }

    public function update(Host $host)
    {
        if ($host == null || $host->getId() == -1) {
            Utils::log(LOG_ERR, "Exception", __FILE__, __LINE__);
            throw new Exception("Host object is not valid or Host.id is not set");
        }
        $dbHost = $this->getById($host->getId());
        if ($dbHost == null) {
            throw new Exception("Host cannot be retreived from the DB");
        }
    
        $entries = array();
        if ($host->getHostname() != $dbHost->getHostname()) {
            $entries['hostname'] = "'".$this->db->escape($host->getHostname())."'";
        }
        if ($host->getIp() != $dbHost->getIp()) {
            $entries['ip'] = "'".$this->db->escape($host->getIp())."'";
        }
        if ($host->getReporterHostname() != $dbHost->getReporterHostname()) {
            $entries['reporterHostname'] = "'".$this->db->escape($host->getReporterHostname())."'";
        }
        if ($host->getReporterIp() != $dbHost->getReporterIp()) {
            $entries['reporterIp'] = "'".$this->db->escape($host->getReporterIp())."'";
        }
        if ($host->getKernel() != $dbHost->getKernel()) {
            $entries['kernel'] ="'". $this->db->escape($host->getKernel())."'";
        }
        if ($host->getOsId() != $dbHost->getOsId()) {
            $entries['osId'] = $this->db->escape($host->getOsId());
        }
        if ($host->getArchId() != $dbHost->getArchId()) {
            $entries['archId'] = $this->db->escape($host->getArchId());
        }
        if ($host->getDomainId() != $dbHost->getDomainId()) {
            $entries['domainId'] = $this->db->escape($host->getDomainId());
        }
        if ($host->getNumOfCves() != $dbHost->getNumOfCves()) {
            $entries['numOfCves'] = $this->db->escape($host->getNumOfCves());
        }
        if ($host->getNumOfCvesWithTag() != $dbHost->getNumOfCvesWithTag()) {
            $entries['numOfCvesWithTag'] = $this->db->escape($host->getNumOfCvesWithTag());
        }
        if ($host->getPkgTypeId() != $dbHost->getPkgTypeId()) {
            $entries['pkgTypeId'] = "'".$this->db->escape($host->getPkgTypeId())."'";
        }

        if (sizeof($entries) > 0) {
            # Construct SQL query
            $sql = "update Host set";
            $sqle = "";
            foreach ($entries as $column => $value) {
                $sqle .= " $column=$value,";
            }

            # Remove last comma
            $sqle = preg_replace('/(.*),$/', '\1', $sqle);
            $sql .= $sqle . " where id=".$host->getId();
            $this->db->query($sql);

            Utils::log(LOG_DEBUG, "Host updated", __FILE__, __LINE__);
        }
    }

    public function delete($id)
    {
        $sql = "delete from Host
            where id='".$this->db->escape($id)."'";
        $this->db->query($sql);
        return $this->db->getNumberOfAffectedRows();
    }

    public function setLastReportId($hostId, $reportId)
    {
        $this->db->query("update Host set lastReportId=$reportId where id=$hostId");
    }
}
