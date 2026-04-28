const hre = require("hardhat");
const fs = require("fs");
const path = require("path");

async function main() {
  const [deployer] = await hre.ethers.getSigners();
  const balance = await hre.ethers.provider.getBalance(deployer.address);
  console.log(`deployer: ${deployer.address}`);
  console.log(`balance:  ${hre.ethers.formatEther(balance)} 0G`);

  if (balance === 0n) {
    throw new Error(
      "deployer has 0 balance — fund it via https://faucet.0g.ai (0.1 0G/day/wallet)"
    );
  }

  const Factory = await hre.ethers.getContractFactory("EvidenceRegistry");
  const contract = await Factory.deploy();
  await contract.waitForDeployment();

  const addr = await contract.getAddress();
  const deployTx = contract.deploymentTransaction();
  console.log(`EvidenceRegistry deployed: ${addr}`);
  console.log(`deploy tx:                 ${deployTx.hash}`);
  console.log(`explorer:                  https://chainscan-galileo.0g.ai/address/${addr}`);

  // Persist address for downstream scripts.
  const out = path.resolve(__dirname, "../../.deploy.json");
  fs.writeFileSync(
    out,
    JSON.stringify(
      {
        network: "ogGalileo",
        chainId: Number((await hre.ethers.provider.getNetwork()).chainId),
        evidenceRegistry: addr,
        deployTx: deployTx.hash,
        deployer: deployer.address,
        timestamp: new Date().toISOString(),
      },
      null,
      2
    )
  );
  console.log(`wrote ${out}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
